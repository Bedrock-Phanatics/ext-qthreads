<?php

declare(strict_types=1);

require __DIR__ . "/common.php";

use pmmp\thread\Pool;
use pmmp\thread\Runnable;
use pmmp\thread\Worker;

/**
 * Async task pool workloads shaped like a Quark server's: lots of small tasks, and chunk tasks which carry a
 * serialized chunk to a worker and bring a bigger result back. Submission keeps a bounded number of tasks in flight,
 * like an async pool does.
 *
 *   php quark.php <async|chunk> <workers>
 */
abstract class Task extends Runnable{
	public int $submitted = 0;
	public int $done = 0;
	public ?string $result = null;
}

class AsyncTask extends Task{
	public function __construct(private string $input){}

	public function run() : void{
		$data = unserialize($this->input);
		$data["handled"] = true;
		$this->result = serialize($data);
		$this->done = hrtime(true);
	}
}

class ChunkTask extends Task{
	public function __construct(private string $chunk){}

	public function run() : void{
		$chunk = $this->chunk;
		//stand-in for light generation work plus encoding the result
		$sub = "";
		for($y = 0; $y < 24; $y++){
			$sub .= str_rot13(substr($chunk, $y * 2048, 2048));
		}
		$this->result = function_exists("zlib_encode") ? zlib_encode($chunk . $sub, ZLIB_ENCODING_DEFLATE, 1) : $chunk . crc32($sub);
		$this->done = hrtime(true);
	}
}

[, $mode, $workers] = $argv + [null, "", 1];
$workers = (int) $workers;
if(!in_array($mode, ["async", "chunk"], true) || $workers < 1){
	fwrite(STDERR, "usage: quark.php <async|chunk> <workers>\n");
	exit(1);
}

mt_srand(1);
$chunk = "";
for($i = 0; $i < 4096; $i++){
	//compressible like real chunk data: runs of a few block ids
	$chunk .= str_repeat(chr(mt_rand(0, 15)), 16);
}
$asyncInput = serialize(["player" => "Steve", "pos" => [12.5, 64.0, -3.25], "items" => range(1, 36), "tag" => str_repeat("n", 128)]);
[$total, $inFlight] = $mode === "async" ? [60_000, 256] : [30_000, 64];

$rssBefore = rssKb();
$pool = new Pool($workers, Worker::class);
//make every worker start up before measuring
for($i = 0; $i < $workers * 4; $i++){
	$pool->submit(new AsyncTask($asyncInput));
}
while($pool->collect() > 0){
	usleep(100);
}
$rssPerWorker = (rssKb() - $rssBefore) / $workers;

$latency = [];
$pending = 0;
$collect = function(Task $task) use (&$latency, &$pending) : bool{
	if($task->done === 0){
		return false;
	}
	$latency[] = ($task->done - $task->submitted) / 1e3;
	$pending--;
	return strlen($task->result) > 0;
};

$before = usage();
$start = hrtime(true);
for($i = 0; $i < $total; $i++){
	while($pending >= $inFlight){
		$was = $pending;
		$pool->collect($collect);
		if($pending === $was){
			usleep(50);
		}
	}
	$task = $mode === "async" ? new AsyncTask($asyncInput) : new ChunkTask($chunk);
	$task->submitted = hrtime(true);
	$pool->submit($task);
	$pending++;
}
while($pending > 0){
	$pool->collect($collect);
	if($pending > 0){
		usleep(50);
	}
}
$wall = (hrtime(true) - $start) / 1e9;
$pool->shutdown();

report("quark-$mode-{$workers}w", [
	"tasks_s" => $total / $wall,
	"p50_us" => percentile($latency, .5),
	"p95_us" => percentile($latency, .95),
	"p99_us" => percentile($latency, .99),
	"worker_rss_kb" => $rssPerWorker,
	"peak_rss_kb" => getrusage()["ru_maxrss"],
], $before);

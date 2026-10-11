<?php

declare(strict_types=1);

require __DIR__ . "/common.php";

use pmmp\thread\Pool;
use pmmp\thread\Runnable;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\Worker;

/**
 * Long-running Pool workload, similar to an async task pool. Memory should level off after warmup;
 * rss_growth_kb is how much it kept growing over the second half of the run.
 */
class Job extends Runnable{
	public ?ThreadSafeArray $result = null;

	public function __construct(private int $id){}

	public function run() : void{
		$result = new ThreadSafeArray();
		$result["id"] = $this->id;
		$result["payload"] = str_repeat("r", 256 + $this->id % 256);
		$result["callback"] = static fn() => true;
		$this->result = $result;
	}
}

$rounds = (int) ($argv[1] ?? 40);
$perRound = 2000;
$pool = new Pool(4, Worker::class);

$rss = [];
$before = usage();
$start = hrtime(true);
for($round = 0; $round < $rounds; $round++){
	for($i = 0; $i < $perRound; $i++){
		$pool->submit(new Job($round * $perRound + $i));
	}
	while($pool->collect(fn(Job $job) => $job->result !== null && ($job->result["callback"])()) > 0){
		usleep(100);
	}
	gc_collect_cycles();
	$rss[] = rssKb();
}
$pool->shutdown();

$half = intdiv($rounds, 2);
report("pool-rss", [
	"tasks_s" => $rounds * $perRound / ((hrtime(true) - $start) / 1e9),
	"rss_mid_kb" => $rss[$half],
	"rss_end_kb" => $rss[$rounds - 1],
	"rss_growth_kb" => $rss[$rounds - 1] - $rss[$half],
], $before);

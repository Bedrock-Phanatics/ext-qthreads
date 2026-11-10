<?php

declare(strict_types=1);

require __DIR__ . "/common.php";

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\Worker;

class Nop extends Runnable{
	public function run() : void{}
}

class Stamp extends Runnable{
	public function __construct(private ThreadSafeArray $out){}

	public function run() : void{
		$this->out[] = hrtime(true);
	}
}

class Crash extends Runnable{
	public function run() : void{
		throw new \RuntimeException("crash");
	}
}

/**
 * Starts a worker, gives it a few tasks and shuts it down, over and over. rss_growth_kb is how much memory
 * kept growing over the second half of the run.
 *
 * @return array<string, int|float>
 */
function cycles(int $n, bool $crash) : array{
	$rss = [];
	$start = hrtime(true);
	for($i = 0; $i < $n; $i++){
		$w = new Worker();
		$w->start(Thread::INHERIT_ALL);
		for($j = 0; $j < 10; $j++){
			$w->stack($crash && $j === 5 ? new Crash() : new Nop());
		}
		$w->shutdown();
		$w->collect();
		$rss[] = rssKb();
	}
	return [
		"cycles_s" => $n / ((hrtime(true) - $start) / 1e9),
		"rss_growth_kb" => $rss[$n - 1] - $rss[intdiv($n, 2)],
	];
}

function waitAll(Worker $w) : void{
	$w->synchronized(function() use ($w) : void{
		while($w->collect() > 0){
			$w->wait();
		}
	});
}

$case = $argv[1] ?? "";
$w = new Worker();
$w->start(Thread::INHERIT_NONE);
for($i = 0; $i < 1000; $i++){
	$w->stack(new Nop());
}
waitAll($w);

$before = usage();
$start = hrtime(true);
switch($case){
	case "burst":
		$n = 200_000;
		for($i = 0; $i < $n; $i++){
			$w->stack(new Nop());
			if($i % 1000 === 999){
				$w->collect();
			}
		}
		waitAll($w);
		report($case, ["tasks_s" => $n / ((hrtime(true) - $start) / 1e9)], $before);
		break;

	case "roundtrip":
		$n = 30_000;
		for($i = 0; $i < $n; $i++){
			$w->stack(new Nop());
			waitAll($w);
		}
		report($case, ["us" => (hrtime(true) - $start) / $n / 1e3], $before);
		break;

	case "wake-latency":
		//the worker is idle when each task arrives
		$n = 5000;
		$out = new ThreadSafeArray();
		$submitted = [];
		for($i = 0; $i < $n; $i++){
			usleep(200);
			$submitted[] = hrtime(true);
			$w->stack(new Stamp($out));
			waitAll($w);
		}
		$latency = [];
		foreach($out as $k => $v){
			$latency[] = ($v - $submitted[$k]) / 1e3;
		}
		report($case, ["p50_us" => percentile($latency, .5), "p95_us" => percentile($latency, .95), "p99_us" => percentile($latency, .99)], $before);
		break;

	case "paced":
		//tasks arrive too far apart for spinning to pay off, so cpu_ms is mostly what the worker costs between them
		$n = 2000;
		$out = new ThreadSafeArray();
		$submitted = [];
		for($i = 0; $i < $n; $i++){
			usleep(1000);
			$submitted[] = hrtime(true);
			$w->stack(new Stamp($out));
			if($i % 100 === 99){
				waitAll($w);
			}
		}
		waitAll($w);
		$latency = [];
		foreach($out as $k => $v){
			$latency[] = ($v - $submitted[$k]) / 1e3;
		}
		report($case, ["p50_us" => percentile($latency, .5), "p99_us" => percentile($latency, .99)], $before);
		break;

	case "cycle":
		report($case, cycles(400, false), $before);
		break;

	case "crash-cycle":
		//every worker dies from an uncaught exception halfway through its tasks
		ini_set("display_errors", "0");
		ini_set("log_errors", "0");
		report($case, cycles(400, true), $before);
		break;

	case "idle":
		$pool = [];
		for($i = 0; $i < 8; $i++){
			$pool[] = $p = new Worker();
			$p->start(Thread::INHERIT_NONE);
		}
		$before = usage();
		sleep(3);
		report($case, ["workers" => 8, "seconds" => 3], $before);
		break;

	default:
		fwrite(STDERR, "usage: worker.php <burst|roundtrip|wake-latency|paced|cycle|crash-cycle|idle>\n");
		exit(1);
}
$w->shutdown();

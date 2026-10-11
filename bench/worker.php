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
		fwrite(STDERR, "usage: worker.php <burst|roundtrip|wake-latency|idle>\n");
		exit(1);
}
$w->shutdown();

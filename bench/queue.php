<?php

declare(strict_types=1);

require __DIR__ . "/common.php";

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\ThreadSafeQueue;

/**
 * Compares ThreadSafeQueue with the usual ThreadSafeArray + synchronized()/wait()/notify() channel.
 */
final class Channel{
	public static function push(ThreadSafeArray|ThreadSafeQueue $c, mixed $v) : void{
		if($c instanceof ThreadSafeQueue){
			$c->push($v);
			return;
		}
		$c->synchronized(function() use ($c, $v) : void{
			$c[] = $v;
			$c->notifyOne();
		});
	}

	public static function pop(ThreadSafeArray|ThreadSafeQueue $c) : mixed{
		if($c instanceof ThreadSafeQueue){
			return $c->pop();
		}
		return $c->synchronized(function() use ($c) : mixed{
			while($c->count() === 0){
				$c->wait();
			}
			$v = $c->shift();
			return $v === "CLOSE" ? null : $v;
		});
	}

	public static function close(ThreadSafeArray|ThreadSafeQueue $c, int $consumers) : void{
		if($c instanceof ThreadSafeQueue){
			$c->close();
			return;
		}
		$c->synchronized(function() use ($c, $consumers) : void{
			for($i = 0; $i < $consumers; $i++){
				$c[] = "CLOSE";
			}
			$c->notify();
		});
	}
}

class Producer extends Thread{
	public function __construct(private ThreadSafeArray|ThreadSafeQueue $c, private int $n, private bool $paced, private int $payload){}

	public function run() : void{
		$pad = ":" . str_repeat("x", $this->payload);
		for($i = 0; $i < $this->n; $i++){
			if($this->paced){
				usleep(200);
			}
			Channel::push($this->c, $this->payload > 0 ? hrtime(true) . $pad : hrtime(true));
		}
	}
}

class Consumer extends Thread{
	public function __construct(private ThreadSafeArray|ThreadSafeQueue $c, private ThreadSafeArray $out){}

	public function run() : void{
		$latency = [];
		while(($v = Channel::pop($this->c)) !== null){
			$latency[] = hrtime(true) - (int) $v;
		}
		$this->out->synchronized(function() use ($latency) : void{
			$this->out[] = ThreadSafeArray::fromArray($latency);
		});
	}
}

[, $impl, $mode, $producers, $consumers, $payload] = $argv + [null, "", "", 1, 1, 0];
$payload = (int) $payload;
$producers = (int) $producers;
$consumers = (int) $consumers;
if(!in_array($impl, ["queue", "array"], true) || !in_array($mode, ["throughput", "latency"], true) || $producers < 1 || $consumers < 1){
	fwrite(STDERR, "usage: queue.php <queue|array> <throughput|latency> <producers> <consumers>\n");
	exit(1);
}
$items = $mode === "latency" ? 4000 : ($payload >= 1024 ? 100_000 : 400_000);

$c = $impl === "queue" ? new ThreadSafeQueue() : new ThreadSafeArray();
$out = new ThreadSafeArray();
$before = usage();
$start = hrtime(true);
$threads = [];
for($i = 0; $i < $consumers; $i++){
	$threads[] = $t = new Consumer($c, $out);
	$t->start(Thread::INHERIT_ALL);
}
$producerThreads = [];
for($i = 0; $i < $producers; $i++){
	$producerThreads[] = $t = new Producer($c, intdiv($items, $producers), $mode === "latency", $payload);
	$t->start(Thread::INHERIT_ALL);
}
foreach($producerThreads as $t){
	$t->join();
}
Channel::close($c, $consumers);
foreach($threads as $t){
	$t->join();
}
$wall = (hrtime(true) - $start) / 1e9;

$latency = [];
foreach($out as $samples){
	foreach($samples as $ns){
		$latency[] = $ns / 1e3;
	}
}
report("$impl-$mode-{$producers}x$consumers" . ($payload > 0 ? "-{$payload}b" : ""), [
	"ops_s" => count($latency) / $wall,
	"p50_us" => percentile($latency, .5),
	"p95_us" => percentile($latency, .95),
	"p99_us" => percentile($latency, .99),
], $before);

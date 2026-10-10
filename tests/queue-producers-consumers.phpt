--TEST--
Test ThreadSafeQueue with many producers and consumers, close() wakeups and timeout races
--INI--
max_execution_time=120
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\ThreadSafeQueue;

const PER_PRODUCER = 3000;

class Producer extends Thread{
	public function __construct(private ThreadSafeQueue $q, private int $id){}

	public function run() : void{
		for($i = 0; $i < PER_PRODUCER; $i++){
			$this->q->push($i % 3 === 0 ? "{$this->id}:$i" : $this->id * 1_000_000 + $i);
		}
	}
}

class Consumer extends Thread{
	public function __construct(private ThreadSafeQueue $q, private ThreadSafeArray $results, private int $timeout){}

	public function run() : void{
		$count = 0;
		$sum = 0;
		$last = [];
		$outOfOrder = 0;
		while(true){
			$v = $this->timeout > 0 ? $this->q->pop($this->timeout) : $this->q->pop();
			if($v === null){
				if($this->q->isClosed() && count($this->q) === 0){
					break;
				}
				continue; //timed out
			}
			if(is_string($v)){
				[$id, $i] = array_map("intval", explode(":", $v));
			}else{
				$id = intdiv($v, 1_000_000);
				$i = $v % 1_000_000;
			}
			//values from one producer must come out in the order they went in
			if(isset($last[$id]) && $last[$id] >= $i){
				$outOfOrder++;
			}
			$last[$id] = $i;
			$count++;
			$sum += $i;
		}
		$this->results->synchronized(function() use ($count, $sum, $outOfOrder) : void{
			$this->results["count"] += $count;
			$this->results["sum"] += $sum;
			$this->results["outOfOrder"] += $outOfOrder;
		});
	}
}

foreach([[1, 1, 0], [2, 2, 0], [4, 4, 0], [8, 8, 0], [4, 8, 50], [8, 2, 1]] as [$producers, $consumers, $timeout]){
	$q = new ThreadSafeQueue();
	$results = ThreadSafeArray::fromArray(["count" => 0, "sum" => 0, "outOfOrder" => 0]);

	$threads = [];
	for($i = 0; $i < $consumers; $i++){
		$threads[] = $t = new Consumer($q, $results, $timeout);
		$t->start(Thread::INHERIT_ALL);
	}
	$producerThreads = [];
	for($i = 0; $i < $producers; $i++){
		$producerThreads[] = $t = new Producer($q, $i);
		$t->start(Thread::INHERIT_ALL);
	}
	foreach($producerThreads as $t){
		$t->join();
	}
	$q->close();
	foreach($threads as $t){
		$t->join();
	}

	$expectedSum = $producers * (PER_PRODUCER * (PER_PRODUCER - 1) / 2);
	printf(
		"%d producers, %d consumers, timeout %d: count %s, sum %s, order %s\n",
		$producers, $consumers, $timeout,
		$results["count"] === $producers * PER_PRODUCER ? "ok" : "BAD " . $results["count"],
		$results["sum"] == $expectedSum ? "ok" : "BAD",
		$results["outOfOrder"] === 0 ? "ok" : "BAD"
	);
}

//close() wakes every blocked consumer
$q = new ThreadSafeQueue();
$results = ThreadSafeArray::fromArray(["count" => 0, "sum" => 0, "outOfOrder" => 0]);
$threads = [];
for($i = 0; $i < 8; $i++){
	$threads[] = $t = new Consumer($q, $results, 0);
	$t->start(Thread::INHERIT_ALL);
}
usleep(50000);
$q->close();
foreach($threads as $t){
	$t->join();
}
echo "all blocked consumers woke up\n";
?>
--EXPECT--
1 producers, 1 consumers, timeout 0: count ok, sum ok, order ok
2 producers, 2 consumers, timeout 0: count ok, sum ok, order ok
4 producers, 4 consumers, timeout 0: count ok, sum ok, order ok
8 producers, 8 consumers, timeout 0: count ok, sum ok, order ok
4 producers, 8 consumers, timeout 50: count ok, sum ok, order ok
8 producers, 2 consumers, timeout 1: count ok, sum ok, order ok
all blocked consumers woke up

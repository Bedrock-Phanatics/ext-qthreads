--TEST--
Test that Workers never miss a wakeup when tasks are submitted around idle transitions
--FILE--
<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\Worker;

class Inc extends Runnable{
	public function __construct(private ThreadSafeArray $counter){}

	public function run() : void{
		$this->counter->synchronized(function() : void{
			$this->counter["n"] = $this->counter["n"] + 1;
		});
	}
}

function waitAll(Worker $w) : void{
	$w->synchronized(function() use ($w) : void{
		while($w->collect() > 0){
			$w->wait();
		}
	});
}

$counter = new ThreadSafeArray();
$counter["n"] = 0;
$expected = 0;

$w = new Worker();
$w->start(Thread::INHERIT_ALL);

//empty queue -> submission, with the worker idle for varying amounts of time
for($i = 0; $i < 300; $i++){
	if($i % 3 !== 0){
		usleep(mt_rand(0, 300));
	}
	$w->stack(new Inc($counter));
	$expected++;
	waitAll($w);
	if($counter["n"] !== $expected){
		echo "missed task at $i\n";
	}
}

//rapid submissions
for($i = 0; $i < 20000; $i++){
	$w->stack(new Inc($counter));
	$expected++;
	if($i % 1000 === 0){
		$w->collect();
	}
}
waitAll($w);

//submissions racing the worker going back to sleep
for($i = 0; $i < 3000; $i++){
	$w->stack(new Inc($counter));
	for($j = mt_rand(0, 2000); $j > 0; $j--);
	$w->stack(new Inc($counter));
	$expected += 2;
	if($i % 100 === 0){
		waitAll($w);
	}
}
waitAll($w);
var_dump($counter["n"] === $expected);

//shutdown with queued work drains the queue first
for($i = 0; $i < 100; $i++){
	$w->stack(new Inc($counter));
	$expected++;
}
$w->shutdown();
var_dump($counter["n"] === $expected);

//shutdown while idle, never started, and right after start
for($i = 0; $i < 50; $i++){
	$idle = new Worker();
	if($i % 5 !== 0){
		$idle->start(Thread::INHERIT_NONE);
		if($i % 2 === 0){
			usleep(mt_rand(0, 500));
		}
		$idle->shutdown();
	}
	unset($idle);
}

//several threads, each feeding its own worker, all contending on the same counter
class Producer extends Thread{
	public function __construct(private ThreadSafeArray $counter){}

	public function run() : void{
		$w = new Worker();
		$w->start(Thread::INHERIT_ALL);
		for($i = 0; $i < 5000; $i++){
			$w->stack(new Inc($this->counter));
			if($i % 500 === 0){
				$w->collect();
			}
		}
		$w->shutdown();
		while($w->collect() > 0);
	}
}

$producers = [];
for($i = 0; $i < 4; $i++){
	$p = new Producer($counter);
	$p->start(Thread::INHERIT_ALL);
	$producers[] = $p;
}
foreach($producers as $p){
	$p->join();
}
$expected += 4 * 5000;
var_dump($counter["n"] === $expected);
echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done

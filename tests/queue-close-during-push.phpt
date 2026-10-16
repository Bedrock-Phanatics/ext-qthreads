--TEST--
Test ThreadSafeQueue close() during a push either rejects the value or lets exactly one consumer pop it, and wakes every consumer
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\ThreadSafeQueue;

class Consumer extends Thread{
	public function __construct(private ThreadSafeQueue $q, private ThreadSafeArray $state){}

	public function run() : void{
		$this->state->synchronized(function() : void{
			$this->state["waiting"] += 1;
			$this->state->notify();
		});
		while($this->q->pop() !== null){
			$this->state->synchronized(fn() => $this->state["popped"] += 1);
		}
	}
}

class Pusher extends Thread{
	public function __construct(private ThreadSafeQueue $q, private ThreadSafeArray $state){}

	public function run() : void{
		//a big array makes the push slow enough for close() to land in the middle of it
		$payload = range(1, 200_000);
		$this->state->synchronized(function() : void{
			$this->state["pushing"] = true;
			$this->state->notify();
		});
		try{
			$this->q->push($payload);
		}catch(\RuntimeException){
			$this->state["rejected"] = true;
		}
	}
}

for($i = 0; $i < 10; $i++){
	$q = new ThreadSafeQueue();
	$state = ThreadSafeArray::fromArray(["waiting" => 0, "pushing" => false, "popped" => 0, "rejected" => false]);
	$consumers = [];
	for($j = 0; $j < 3; $j++){
		$consumers[] = $t = new Consumer($q, $state);
		$t->start(Thread::INHERIT_ALL);
	}
	$state->synchronized(function() use ($state) : void{
		while($state["waiting"] < 3){
			$state->wait();
		}
	});
	usleep(10000);
	$pusher = new Pusher($q, $state);
	$pusher->start(Thread::INHERIT_ALL);
	$state->synchronized(function() use ($state) : void{
		while(!$state["pushing"]){
			$state->wait();
		}
	});
	$q->close();
	//the pushed array dies with the pusher, so it has to outlive the consumers
	foreach($consumers as $t){
		$t->join();
	}
	$pusher->join();
	//whichever way close() lands, the value must not be lost or duplicated
	if($state["popped"] + (int) $state["rejected"] !== 1){
		echo "popped ", $state["popped"], ", rejected ", var_export($state["rejected"], true), "\n";
	}
}
echo "OK\n";
?>
--EXPECT--
OK

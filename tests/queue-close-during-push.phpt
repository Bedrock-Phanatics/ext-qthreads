--TEST--
Test ThreadSafeQueue wakes every waiting consumer when close() lands during a push that succeeds
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
			//close() won the race
		}
	}
}

for($i = 0; $i < 10; $i++){
	$q = new ThreadSafeQueue();
	$state = ThreadSafeArray::fromArray(["waiting" => 0, "pushing" => false, "popped" => 0]);
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
	$pusher->join();
	foreach($consumers as $t){
		$t->join();
	}
	if($state["popped"] > 1){
		echo "popped ", $state["popped"], "\n";
	}
}
echo "OK\n";
?>
--EXPECT--
OK

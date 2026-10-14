--TEST--
Test ThreadSafeQueue::pop() blocking inside the queue's own synchronized() doesn't stop other threads pushing
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\ThreadSafeQueue;

$q = new ThreadSafeQueue();
$state = ThreadSafeArray::fromArray(["locked" => false]);

$consumer = new class($q, $state) extends Thread{
	public function __construct(private ThreadSafeQueue $q, private ThreadSafeArray $state){}

	public function run() : void{
		$v = $this->q->synchronized(function() : mixed{
			$this->state->synchronized(function() : void{
				$this->state["locked"] = true;
				$this->state->notify();
			});
			//bounded so a regression fails instead of hanging
			return $this->q->pop(5_000_000);
		});
		var_dump($v);
	}
};
$consumer->start(Thread::INHERIT_ALL);

$state->synchronized(function() use ($state) : void{
	while(!$state["locked"]){
		$state->wait();
	}
});
$q->push("hello");
$consumer->join();
?>
--EXPECT--
string(5) "hello"

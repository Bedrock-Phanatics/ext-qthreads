--TEST--
Test that threads are joined when a fatal error skips the destructors of their creator
--FILE--
<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\Worker;

function await(ThreadSafeArray $gate, string $key, int $value) : void{
	$gate->synchronized(function() use ($gate, $key, $value) : void{
		while($gate[$key] < $value){
			$gate->wait();
		}
	});
}

function bump(ThreadSafeArray $gate, string $key) : void{
	$gate->synchronized(function() use ($gate, $key) : void{
		$gate[$key] = $gate[$key] + 1;
		$gate->notify();
	});
}

class Task extends Runnable{
	public function __construct(private int $id, private ThreadSafeArray $gate){}

	public function run() : void{
		await($this->gate, "go", 1);
		$array = new ThreadSafeArray();
		$array[] = $this->id;
		echo "task {$this->id}\n";
		bump($this->gate, "done");
	}
}

$gate = ThreadSafeArray::fromArray(["go" => 0, "done" => 0]);

$worker = new Worker();
$worker->start(Thread::INHERIT_ALL);
for($i = 0; $i < 3; $i++){
	$worker->stack(new Task($i, $gate));
}

$thread = new class($gate) extends Thread{
	public function __construct(private ThreadSafeArray $gate){}

	public function run() : void{
		await($this->gate, "done", 3);
		echo "thread\n";
	}
};
$thread->start(Thread::INHERIT_ALL);

//shutdown functions still run after a fatal error, but the threads are only joined after them
register_shutdown_function(fn() => bump($gate, "go"));

eval('class Task{}');
?>
--EXPECTF--
Fatal error: Cannot redeclare class Task %s on line %d
task 0
task 1
task 2
thread

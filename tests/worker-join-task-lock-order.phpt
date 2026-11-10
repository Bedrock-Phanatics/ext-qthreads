--TEST--
Test that joining a Worker doesn't hold its monitor while syncing finished tasks
--FILE--
<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\Worker;

class Task extends Runnable{
	public function run() : void{}
}

class Holder extends Thread{
	public function __construct(private Task $task, private Worker $worker, private ThreadSafeArray $held){}

	public function run() : void{
		$worker = $this->worker;
		$held = $this->held;
		$this->task->synchronized(function() use ($worker, $held) : void{
			$held[] = true;
			//the creator is joining the worker by now
			usleep(200_000);
			$worker->synchronized(function() : void{});
		});
	}
}

$worker = new Worker();
$worker->start(Thread::INHERIT_ALL);
$task = new Task();
$worker->stack($task);
while($worker->getStacked() > 0){
	usleep(1000);
}

$held = new ThreadSafeArray();
$holder = new Holder($task, $worker, $held);
$holder->start(Thread::INHERIT_ALL);
while(count($held) === 0){
	usleep(1000);
}
$worker->shutdown();
$holder->join();
echo "done\n";
?>
--EXPECT--
done

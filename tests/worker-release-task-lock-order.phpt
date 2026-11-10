--TEST--
Test that a Worker releasing collected tasks doesn't deadlock with their users calling into the Worker
--FILE--
<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\Worker;

class Task extends Runnable{
	public function run() : void{}
}

$worker = new Worker();
$worker->start(Thread::INHERIT_ALL);

$rounds = getenv("USE_ZEND_ALLOC") === "0" && getenv("USE_TRACKED_ALLOC") === false ? 500 : 20000;
for($i = 0; $i < $rounds; $i++){
	$task = new Task();
	$worker->stack($task);
	while($worker->collect() > 0);
	//the worker drops its connection to the task about now
	$task->synchronized(function() use ($worker) : void{
		$worker->getStacked();
	});
}
$worker->shutdown();
echo "done\n";
?>
--EXPECT--
done

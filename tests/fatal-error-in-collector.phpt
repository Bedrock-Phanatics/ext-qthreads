--TEST--
Test that a fatal error in a collector doesn't break the worker's shutdown
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
for($i = 0; $i < 5; $i++){
	$worker->stack(new Task());
}
while($worker->getStacked() > 0){
	usleep(1000);
}

$worker->collect(function(Task $task) : bool{
	eval('class Task{}');
	return true;
});
?>
--EXPECTF--
Fatal error: Cannot redeclare class Task %s on line %d

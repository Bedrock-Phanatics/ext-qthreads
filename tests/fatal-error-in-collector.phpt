--TEST--
Test that a fatal error in a collector doesn't break the worker's shutdown
--SKIPIF--
<?php $s = function_exists("opcache_get_status") ? opcache_get_status(false) : false; if(PHP_OS_FAMILY === "Windows" && is_array($s) && !empty($s["jit"]["on"])) die("skip a bailout through JIT frames crashes in RtlUnwind on Windows"); ?>
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

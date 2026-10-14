--TEST--
Test Worker::collect() called from a collector counts the tasks the outer call hasn't collected yet
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\Worker;
use pmmp\thread\Runnable;

$worker = new Worker();
$worker->start(Thread::INHERIT_ALL);
for($i = 0; $i < 3; $i++){
	$worker->stack(new class extends Runnable{
		public function run() : void{}
	});
}
$worker->shutdown();

echo $worker->collect(function(Runnable $task) use ($worker) : bool{
	echo "nested: ", $worker->collect(), "\n";
	return true;
}), "\n";
?>
--EXPECT--
nested: 3
nested: 2
nested: 1
0

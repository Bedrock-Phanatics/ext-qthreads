--TEST--
Test that threads are joined when a fatal error skips the destructors of their creator
--FILE--
<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\Worker;

class Task extends Runnable{
	public function __construct(private int $id){}

	public function run() : void{
		usleep(50_000);
		$array = new ThreadSafeArray();
		$array[] = $this->id;
		echo "task {$this->id}\n";
	}
}

$worker = new Worker();
$worker->start(Thread::INHERIT_ALL);
for($i = 0; $i < 3; $i++){
	$worker->stack(new Task($i));
}

$thread = new class extends Thread{
	public function run() : void{
		usleep(300_000);
		echo "thread\n";
	}
};
$thread->start(Thread::INHERIT_ALL);

eval('class Task{}');
?>
--EXPECTF--
Fatal error: Cannot redeclare class Task %s on line %d
task 0
task 1
task 2
thread

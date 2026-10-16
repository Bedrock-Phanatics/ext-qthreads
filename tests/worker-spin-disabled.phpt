--TEST--
Test workers run and collect tasks correctly with spinning disabled
--INI--
pmmpthread.worker_spin_us=0
--FILE--
<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\Worker;

class Task extends Runnable{
	public ?int $result = null;

	public function __construct(private int $n){}

	public function run() : void{
		$this->result = $this->n * 2;
	}
}

var_dump(ini_get("pmmpthread.worker_spin_us"));

$worker = new Worker();
$worker->start(Thread::INHERIT_ALL);
$sum = 0;
for($i = 0; $i < 2000; $i++){
	$worker->stack(new Task($i));
	if($i % 7 === 0){
		usleep(100);
	}
	$worker->collect(function(Task $task) use (&$sum) : bool{
		if($task->result === null){
			return false;
		}
		$sum += $task->result;
		return true;
	});
}
$worker->shutdown();
$worker->collect(function(Task $task) use (&$sum) : bool{
	$sum += $task->result;
	return true;
});
var_dump($sum === 2000 * 1999);

?>
--EXPECT--
string(1) "0"
bool(true)

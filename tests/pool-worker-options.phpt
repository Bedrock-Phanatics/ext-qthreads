--TEST--
Test that Pool passes its inheritance options to the Workers it starts
--FILE--
<?php

use pmmp\thread\Pool;
use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\Worker;

function parentOnly() : void{}

class Probe extends Runnable{
	public function run() : void{
		var_dump(function_exists("parentOnly"));
	}
}

foreach([Thread::INHERIT_ALL, Thread::INHERIT_NONE] as $options){
	$pool = new Pool(1, Worker::class, [], $options);
	$pool->submit(new Probe());
	$pool->shutdown();
}

$pool = new Pool(1);
$pool->submit(new Probe());
$pool->shutdown();

try{
	new Pool(1, Worker::class, [], PHP_INT_MAX);
}catch(\ValueError $e){
	echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
pmmp\thread\Pool::__construct(): Argument #4 ($options) must be a combination of Thread::INHERIT_* and Thread::ALLOW_HEADERS flags

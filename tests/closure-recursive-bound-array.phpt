--TEST--
Test that copying a closure which captured an array containing itself doesn't recurse forever
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

$recursive = ["value" => 1];
$recursive["self"] = &$recursive;

$array = new ThreadSafeArray();
$array["closure"] = function() use ($recursive) : int{
	return $recursive["value"];
};

$thread = new class($array) extends Thread{
	public function __construct(private ThreadSafeArray $array){}

	public function run() : void{
		var_dump(($this->array["closure"])());
	}
};
$thread->start(Thread::INHERIT_ALL);
$thread->join();
?>
--EXPECT--
int(1)

--TEST--
Test that a thread running out of memory while it reads a shared object doesn't leave the object locked
--SKIPIF--
<?php if(getenv("USE_ZEND_ALLOC") === "0" && getenv("USE_TRACKED_ALLOC") === false) die("skip memory_limit needs the Zend allocator"); ?>
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

$array = new ThreadSafeArray();
$array["big"] = str_repeat("x", 32 << 20);

$thread = new class($array) extends Thread{
	public function __construct(private ThreadSafeArray $array){}

	public function run() : void{
		ini_set("memory_limit", "8M");
		$copy = $this->array["big"];
	}
};
$thread->start(Thread::INHERIT_ALL);
$thread->join();
var_dump(strlen($array["big"]));
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
int(33554432)

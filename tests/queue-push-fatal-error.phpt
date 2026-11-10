--TEST--
Test that a push dying from a fatal error doesn't leave consumers of the closed queue waiting
--SKIPIF--
<?php $s = function_exists("opcache_get_status") ? opcache_get_status(false) : false; if(PHP_OS_FAMILY === "Windows" && is_array($s) && !empty($s["jit"]["on"])) die("skip a bailout through JIT frames crashes in RtlUnwind on Windows");
if(getenv("USE_ZEND_ALLOC") === "0" && getenv("USE_TRACKED_ALLOC") === false) die("skip memory_limit needs the Zend allocator"); ?>
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeQueue;

$queue = new ThreadSafeQueue();
$thread = new class($queue) extends Thread{
	public function __construct(private ThreadSafeQueue $queue){}

	public function run() : void{
		$strings = [];
		for($i = 0; $i < 300_000; $i++){
			$strings[] = "string number $i";
		}
		ini_set("memory_limit", (string) (memory_get_usage() + (1 << 20)));
		$this->queue->push($strings);
	}
};
$thread->start(Thread::INHERIT_ALL);
$thread->join();
$queue->close();
var_dump($queue->pop());
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
NULL

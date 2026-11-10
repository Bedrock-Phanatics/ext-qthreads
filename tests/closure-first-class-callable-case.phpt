--TEST--
Test that first-class callables of functions and methods with uppercase names can be shared
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

class Service extends ThreadSafe{
	public function doWork() : string{
		return "method";
	}

	public static function doStatic() : string{
		return "static";
	}
}

function DoWork() : string{
	return "function";
}

$array = new ThreadSafeArray();
$service = new Service();
$array["method"] = $service->doWork(...);
$array["static"] = Service::doStatic(...);
$array["function"] = DoWork(...);

$thread = new class($array) extends Thread{
	public function __construct(private ThreadSafeArray $array){}

	public function run() : void{
		foreach(["method", "static", "function"] as $name){
			var_dump(($this->array[$name])());
		}
	}
};
$thread->start(Thread::INHERIT_ALL);
$thread->join();
?>
--EXPECT--
string(6) "method"
string(6) "static"
string(8) "function"

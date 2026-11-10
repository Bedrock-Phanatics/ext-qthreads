--TEST--
Test that threads copying a class with an internal parent don't leak its property infos
--FILE--
<?php

use pmmp\thread\Thread;

class CustomException extends \Exception{
	public int $extra = 1;
}

$thread = new class extends Thread{
	public function run() : void{
		$e = new CustomException("message");
		var_dump($e->getMessage(), $e->extra);
	}
};
$thread->start(Thread::INHERIT_ALL);
$thread->join();
?>
--EXPECT--
string(7) "message"
int(1)

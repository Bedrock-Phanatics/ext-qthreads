--TEST--
Test that a thread can join its own threads from destructors when it shuts down
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

class GrandChild extends Thread{
	public function run() : void{
		usleep(100_000);
		$array = new ThreadSafeArray();
		$array[] = 1;
		echo "grandchild\n";
	}
}

class Child extends Thread{
	public static ?GrandChild $keep = null;

	public function run() : void{
		self::$keep = new GrandChild();
		self::$keep->start(Thread::INHERIT_ALL);
	}
}

$child = new Child();
$child->start(Thread::INHERIT_ALL);
$child->join();
echo "done\n";
?>
--EXPECT--
grandchild
done

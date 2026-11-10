--TEST--
Test that threads can look up int backed enum cases outside the 32-bit unsigned range
--FILE--
<?php

use pmmp\thread\Thread;

enum Big : int{
	case NEGATIVE = -1;
	case HUGE = 5_000_000_000;
	case SMALL = 3;
}

enum Name : string{
	case X = "ex";
}

//makes the creator build the lookup tables which the thread then copies
var_dump(Big::from(-1), Name::from("ex"));

$thread = new class extends Thread{
	public function run() : void{
		var_dump(Big::tryFrom(-1), Big::tryFrom(5_000_000_000), Big::tryFrom(3), Big::tryFrom(4_294_967_295), Name::tryFrom("ex"));
	}
};
$thread->start(Thread::INHERIT_ALL);
$thread->join();
?>
--EXPECT--
enum(Big::NEGATIVE)
enum(Name::X)
enum(Big::NEGATIVE)
enum(Big::HUGE)
enum(Big::SMALL)
NULL
enum(Name::X)

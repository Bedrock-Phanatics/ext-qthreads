--TEST--
Test that enums copied between ThreadSafeArrays by merge() own their own storage
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

enum Suit{
	case Hearts;
	case Spades;
}

enum Status : string{
	case Active = "active";
}

for($i = 0; $i < 100; $i++){
	$a = new ThreadSafeArray();
	$a["suit"] = Suit::Hearts;
	$a[] = Status::Active;
	$b = new ThreadSafeArray();
	$b->merge($a);
	unset($a);
	if($b["suit"] !== Suit::Hearts || $b[0] !== Status::Active){
		echo "bad copy at $i\n";
	}
	unset($b);
}

//destroy the source and the copy on different threads
$a = new ThreadSafeArray();
$a["suit"] = Suit::Spades;
$t = new class($a) extends Thread{
	public function __construct(private ThreadSafeArray $a){}

	public function run() : void{
		$b = new ThreadSafeArray();
		$b->merge($this->a);
		var_dump($b["suit"]);
	}
};
$t->start(Thread::INHERIT_ALL);
$t->join();
var_dump($a["suit"]);
echo "OK\n";
?>
--EXPECT--
enum(Suit::Spades)
enum(Suit::Spades)
OK

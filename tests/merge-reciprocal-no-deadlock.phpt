--TEST--
Test that concurrent A->merge(B) and B->merge(A) don't deadlock
--INI--
max_execution_time=60
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

class Merger extends Thread{
	public function __construct(
		private ThreadSafeArray $into,
		private ThreadSafeArray $from,
		private ThreadSafeArray $start
	){}

	public function run() : void{
		$this->start->synchronized(function() : void{
			while(!isset($this->start["go"])){
				$this->start->wait();
			}
		});
		for($i = 0; $i < 3000; $i++){
			$this->into->merge($this->from);
			$this->into->merge($this->into);
		}
	}
}

$a = ThreadSafeArray::fromArray(["a" => 1, 2, 3, "s" => str_repeat("a", 20)]);
$b = ThreadSafeArray::fromArray(["b" => 1, 4, 5, "t" => str_repeat("b", 20)]);
$start = new ThreadSafeArray();

$threads = [];
for($i = 0; $i < 4; $i++){
	$t = $i % 2 === 0 ? new Merger($a, $b, $start) : new Merger($b, $a, $start);
	$t->start(Thread::INHERIT_ALL);
	$threads[] = $t;
}
$start->synchronized(function() use ($start) : void{
	$start["go"] = true;
	$start->notify();
});
foreach($threads as $t){
	$t->join();
}

var_dump(count($a) === count($b), isset($a["b"], $a["t"], $b["a"], $b["s"]));
echo "OK\n";
?>
--EXPECT--
bool(true)
bool(true)
OK

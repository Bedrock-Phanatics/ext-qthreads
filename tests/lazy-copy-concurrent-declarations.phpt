--TEST--
Test lazily copying functions and classes from a thread that keeps declaring new ones
--DESCRIPTION--
Copying a closure or connecting to an object pulls in functions and classes the receiving thread doesn't have yet.
Those must never be looked up in the owner's live function/class tables, which it may be growing at the same time.
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeQueue;

class Consumer extends Thread{
	public function __construct(private ThreadSafeQueue $in, private ThreadSafeQueue $out){}

	public function run() : void{
		$sum = 0;
		while(($v = $this->in->pop()) !== null){
			$sum += $v instanceof \Closure ? $v() : $v->get();
		}
		$this->out->push($sum);
	}
}

$rounds = getenv("USE_ZEND_ALLOC") === "0" && getenv("USE_TRACKED_ALLOC") === false ? 100 : 1500;
$perRound = 8;
$in = new ThreadSafeQueue();
$out = new ThreadSafeQueue();
$consumers = [];
for($i = 0; $i < 2; $i++){
	$consumers[] = $t = new Consumer($in, $out);
	$t->start(Thread::INHERIT_ALL);
}

$expected = 0;
for($r = 0; $r < $rounds; $r++){
	$calls = [];
	$code = "";
	for($j = 0; $j < $perRound; $j++){
		$code .= "function lazy_{$r}_{$j}() : int{ return $j; }\n";
		$calls[] = "lazy_{$r}_{$j}()";
	}
	$sum = implode(" + ", $calls);
	//INIT_FCALL to functions and DECLARE_ANON_CLASS which only exist on this thread
	$code .= "class LazyObj_{$r} extends \\pmmp\\thread\\ThreadSafe{ public function get() : int{ return $sum + (new class{ public int \$v = 1; })->v; } }\n";
	$code .= "return [static function() : int{ return $sum + (new class{ public int \$v = 1; })->v; }, new LazyObj_{$r}()];";
	[$closure, $object] = eval($code);
	$expected += 2 * ($perRound * ($perRound - 1) / 2 + 1);
	$in->push($closure);
	$in->push($object);
}
$in->close();
$total = 0;
foreach($consumers as $t){
	$t->join();
	$total += $out->pop();
}
var_dump($total === $expected);
?>
--EXPECT--
bool(true)

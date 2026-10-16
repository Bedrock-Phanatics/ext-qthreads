--TEST--
Test lazily copying classes while the owner evaluates their constants and links their anonymous classes
--DESCRIPTION--
Without opcache, the declaring thread evaluates class constants and default values, and links anonymous classes, the
first time it uses them. Threads copying those classes at the same time must not see them change underneath.
--SKIPIF--
<?php
//opcache keeps the classes it caches immutable, but not eval()'d ones, which this needs
if(function_exists("opcache_get_status") && opcache_get_status(false) !== false) die("skip only without opcache");
?>
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeQueue;

define("K", "k");

abstract class Base{
	const BASE = K . "base";
	public array $baseProp = [self::BASE, K];

	public function base() : int{
		return strlen(static::BASE) + count($this->baseProp);
	}
}

class Consumer extends Thread{
	public function __construct(private ThreadSafeQueue $in, private ThreadSafeQueue $out){}

	public function run() : void{
		$sum = 0;
		while(($v = $this->in->pop()) !== null){
			$sum += $v->get();
		}
		$this->out->push($sum);
	}
}

$rounds = getenv("USE_ZEND_ALLOC") === "0" && getenv("USE_TRACKED_ALLOC") === false ? 100 : 1500;
$in = new ThreadSafeQueue();
$out = new ThreadSafeQueue();
$consumers = [];
for($i = 0; $i < 2; $i++){
	$consumers[] = $t = new Consumer($in, $out);
	$t->start(Thread::INHERIT_ALL);
}

$expected = 0;
for($r = 0; $r < $rounds; $r++){
	$object = eval(<<<CODE
		class LazyObj_$r extends \\pmmp\\thread\\ThreadSafe{
			const A = K . "a$r";
			const B = [self::A, K];
			public string \$s = self::A . K;
			public function get() : int{
				\$o = new class extends \\Base{
					const BASE = K . "anon";
					const D = K . "d";
					public array \$q = [self::D, K, self::BASE];
				};
				return strlen(self::A) + count(self::B) + strlen(\$this->s) + count(\$o->q) + strlen(\$o::D) + \$o->base();
			}
		}
		return new LazyObj_$r();
		CODE);
	$in->push($object);
	$in->push($object);
	//evaluates the constants and links the anonymous class while the consumers copy them
	$expected += 2 * $object->get();
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

--TEST--
Test repeated creation, cross-thread connection and destruction of thread-safe objects
--DESCRIPTION--
Objects are created on both sides, connected from several threads at once and released in different orders,
so that the last reference to a shared object is dropped by both creator and non-creator threads.
Run under Valgrind/ASan to catch leaks and double frees.
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

class Box extends ThreadSafe{
	public string $name = "box";
	public ?ThreadSafe $child = null;
}

class Churn extends Thread{
	public function __construct(
		private ThreadSafeArray $shared,
		private ThreadSafeArray $out,
		private int $id
	){}

	public function run() : void{
		$keep = [];
		for($i = 0; $i < 200; $i++){
			foreach($this->shared as $obj){
				$tmp = $obj->child;
				unset($tmp);
			}

			$box = new Box();
			$box->child = new ThreadSafeArray();
			$box->child[] = str_repeat("x", $i);
			if($i % 10 === $this->id){
				$keep[] = $box;
				$this->out[] = $box;
			}
			unset($box);
		}

		//the parent connects to everything we published before we drop our references
		$this->out->synchronized(function() : void{
			$this->out["done"] = ($this->out["done"] ?? 0) + 1;
			$this->out->notify();
			while(!isset($this->out["taken"])){
				$this->out->wait();
			}
		});
	}
}

$shared = new ThreadSafeArray();
for($i = 0; $i < 16; $i++){
	$box = new Box();
	$box->child = new ThreadSafeArray();
	$shared[] = $box;
}

$out = new ThreadSafeArray();
$threads = [];
for($i = 0; $i < 4; $i++){
	$t = new Churn($shared, $out, $i);
	$t->start(Thread::INHERIT_ALL);
	$threads[] = $t;
}

for($i = 0; $i < 200; $i++){
	$box = new Box();
	$box->child = new ThreadSafeArray();
	$box->child[] = $i;
	unset($box);
}

$taken = $out->synchronized(function() use ($out) : array{
	while(($out["done"] ?? 0) < 4){
		$out->wait();
	}
	unset($out["done"]);
	$taken = [];
	while(($box = $out->shift()) !== null){
		$taken[] = [$box, $box->child];
	}
	$out["taken"] = true;
	$out->notify();
	return $taken;
});

foreach($threads as $t){
	$t->join();
}
unset($threads);

//the creators are gone, so these are now freed by a non-creator thread
$count = 0;
foreach($taken as [$box, $child]){
	if($child->count() === 1 && $box->name === "box"){
		$count++;
	}
}
unset($taken);
var_dump($count);

for($i = 0; $i < 1000; $i++){
	$a = new ThreadSafeArray();
	$a[] = new Box();
	unset($a);
}
echo "OK\n";
?>
--EXPECT--
int(80)
OK

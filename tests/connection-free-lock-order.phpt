--TEST--
Test that dropping a connection doesn't deadlock with a concurrent merge into the same object
--DESCRIPTION--
Freeing a non-last connection used to take the object's monitor while holding the globals lock, while merging nested
arrays takes the globals lock (to create new objects) while holding the object's monitor.
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

class Dropper extends Thread{
	public function __construct(private ThreadSafeArray $boxes){}

	public function run() : void{
		//each box is the only thing keeping this thread's connection to x alive, so it's freed every iteration
		while(($box = $this->boxes->shift()) !== null){
			$x = $box["x"];
			$x["d"] = str_repeat("d", 10);
			unset($x, $box);
		}
	}
}

class Merger extends Thread{
	public function __construct(private ThreadSafeArray $x, private ThreadSafeArray $boxes){}

	public function run() : void{
		for($i = 0; $this->boxes->count() > 0; $i++){
			$this->x->merge(["nested" => [$i, [$i]], "k" . ($i % 8) => [1]]);
		}
		//the arrays we created die when we're joined, which would break syncing $x when joining the other Merger
		$this->x->merge(["nested" => 0, "k0" => 0, "k1" => 0, "k2" => 0, "k3" => 0, "k4" => 0, "k5" => 0, "k6" => 0, "k7" => 0]);
	}
}

//valgrind runs one thread at a time, so use fewer boxes there to fit run-tests' timeout
$n = getenv("USE_ZEND_ALLOC") === "0" && getenv("USE_TRACKED_ALLOC") === false ? 1000 : 20000;
$x = new ThreadSafeArray();
$boxes = new ThreadSafeArray();
for($i = 0; $i < $n; $i++){
	$box = new ThreadSafeArray();
	$box["x"] = $x;
	$boxes[] = $box;
}
$keep = $boxes->chunk($n, true);
foreach($keep as $box){
	$boxes[] = $box;
}

$threads = [new Dropper($boxes), new Dropper($boxes), new Merger($x, $boxes), new Merger($x, $boxes)];
foreach($threads as $t){
	$t->start(Thread::INHERIT_ALL);
}
foreach($threads as $t){
	$t->join();
}
echo "OK\n";
?>
--EXPECT--
OK

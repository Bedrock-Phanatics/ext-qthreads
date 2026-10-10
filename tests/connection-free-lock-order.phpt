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
	}
}

$x = new ThreadSafeArray();
$boxes = new ThreadSafeArray();
for($i = 0; $i < 20000; $i++){
	$box = new ThreadSafeArray();
	$box["x"] = $x;
	$boxes[] = $box;
}
$keep = $boxes->chunk(20000, true);
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

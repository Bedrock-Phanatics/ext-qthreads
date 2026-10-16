--TEST--
Test ThreadSafeQueue doesn't keep consumed strings or self-referencing queues alive on the pushing thread
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeQueue;

class Consumer extends Thread{
	public function __construct(private ThreadSafeQueue $q){}

	public function run() : void{
		while($this->q->pop() !== null);
	}
}

$q = new ThreadSafeQueue();
$before = memory_get_usage();
for($i = 0; $i < 8; $i++){
	$q->push(str_repeat(chr(ord("a") + $i), 1 << 20));
}
$t = new Consumer($q);
$t->start(Thread::INHERIT_ALL);
$q->close();
$t->join();
var_dump(memory_get_usage() - $before < 1 << 20);

$q = new ThreadSafeQueue();
$q->push($q);
$weak = WeakReference::create($q);
unset($q);
gc_collect_cycles();
var_dump($weak->get());

$a = new ThreadSafeQueue();
$b = new ThreadSafeQueue();
$a->push($b);
$b->push($a);
$weak = WeakReference::create($a);
unset($a, $b);
gc_collect_cycles();
var_dump($weak->get());
?>
--EXPECT--
bool(true)
NULL
NULL

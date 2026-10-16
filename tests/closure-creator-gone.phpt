--TEST--
Test that a stored closure stays usable after its creating thread drops it or exits
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

class Maker extends Thread{
	public function __construct(private ThreadSafeArray $out){}

	public function run() : void{
		$prefix = str_repeat("p", 3);
		$fn = function(int $x) use ($prefix) : string{ return $prefix . ($x * 2); };
		$this->out["fn"] = $fn;
		unset($fn);
		//churn the heap so a stale pointer would be overwritten
		$junk = [];
		for($i = 0; $i < 1000; $i++){
			$junk[] = static fn() => str_repeat("x", 64);
		}
	}
}

for($i = 0; $i < 5; $i++){
	$out = new ThreadSafeArray();
	$t = new Maker($out);
	$t->start(Thread::INHERIT_ALL);
	$t->join();
	unset($t);

	$fn = $out["fn"];
	var_dump($fn($i));
}

//creator drops the only local reference before another thread reads it
$out = new ThreadSafeArray();
$out["fn"] = function() : string{ return "still here"; };
$reader = new class($out) extends Thread{
	public function __construct(private ThreadSafeArray $out){}

	public function run() : void{
		$this->out->synchronized(function() : void{
			while(!isset($this->out["go"])){
				$this->out->wait();
			}
		});
		var_dump(($this->out["fn"])());
	}
};
$reader->start(Thread::INHERIT_ALL);
$out->synchronized(function() use ($out) : void{
	$out["go"] = true;
	$out->notify();
});
$reader->join();

//a thread which didn't join the writer reads the closure after the writer exited
class Writer extends Thread{
	public function __construct(private ThreadSafeArray $chan, private ThreadSafeArray $copy){}

	public function run() : void{
		$p = str_repeat("z", 5);
		$this->chan["fn"] = function(int $x) use ($p) : string{ return $p . $x; };
		//the copy is owned by this thread too, since it did the merge
		$this->copy->merge($this->chan);
	}
}

class Reader extends Thread{
	public function __construct(private ThreadSafeArray $chan){}

	public function run() : void{
		$this->chan->synchronized(function() : void{
			while(!isset($this->chan["go"])){
				$this->chan->wait();
			}
		});
		try{
			var_dump(($this->chan["fn"])(42));
		}catch(\pmmp\thread\ConnectionException $e){
			echo $e->getMessage() . "\n";
		}
	}
}

foreach(["chan", "copy"] as $which){
	$chan = new ThreadSafeArray();
	$copy = new ThreadSafeArray();
	$r = new Reader($which === "chan" ? $chan : $copy);
	$r->start(Thread::INHERIT_ALL);
	$w = new Writer($chan, $copy);
	$w->start(Thread::INHERIT_ALL);
	$w->join();
	unset($w);

	//reuse the dead thread's memory
	$t = new class extends Thread{
		public function run() : void{
			$a = [];
			for($i = 0; $i < 20000; $i++){
				$a[] = str_repeat("q", 100);
			}
		}
	};
	$t->start(Thread::INHERIT_ALL);
	$t->join();

	$target = $which === "chan" ? $chan : $copy;
	$target->synchronized(function() use ($target) : void{
		$target["go"] = true;
		$target->notify();
	});
	$r->join();
}

//merging a live closure re-stores it under the merging thread
$a = new ThreadSafeArray();
$a["fn"] = static fn() : string => "merged";
$b = new ThreadSafeArray();
$b->merge($a);
unset($a);
var_dump(($b["fn"])());
echo "OK\n";
?>
--EXPECT--
string(4) "ppp0"
string(4) "ppp2"
string(4) "ppp4"
string(4) "ppp6"
string(4) "ppp8"
string(10) "still here"
pmmpthread detected an attempt to use a closure which has already been destroyed by the thread which stored it
pmmpthread detected an attempt to use a closure which has already been destroyed by the thread which stored it
string(6) "merged"
OK

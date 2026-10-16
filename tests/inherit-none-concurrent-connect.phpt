--TEST--
Test that many INHERIT_NONE threads can resolve the same uninherited classes at once
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

function describe(string $what) : string{
	return "described $what";
}

class Payload extends ThreadSafe{
	public function __construct(public string $name){}

	public function describe() : string{
		$tag = new class{ public string $tag = "tagged"; };
		return describe($this->name) . " " . $tag->tag;
	}
}

class Child extends Payload{}

class Receiver extends Thread{
	public function __construct(private ThreadSafeArray $shared, private ThreadSafeArray $results){}

	public function run() : void{
		$this->shared->synchronized(function() : void{
			while(!isset($this->shared["go"])){
				$this->shared->wait();
			}
		});
		$ok = 0;
		foreach($this->shared as $key => $payload){
			if($payload instanceof Payload && $payload->describe() === "described {$payload->name} tagged"){
				$ok++;
			}
		}
		$this->results->synchronized(function() use ($ok) : void{
			$this->results[] = $ok;
		});
	}
}

$shared = new ThreadSafeArray();
for($i = 0; $i < 20; $i++){
	$shared[] = $i % 2 === 0 ? new Payload("p$i") : new Child("c$i");
}
$results = new ThreadSafeArray();

$threads = [];
for($i = 0; $i < 8; $i++){
	$threads[] = $t = new Receiver($shared, $results);
	$t->start(Thread::INHERIT_NONE);
}
$shared->synchronized(function() use ($shared) : void{
	$shared["go"] = true;
	$shared->notify();
});
foreach($threads as $t){
	$t->join();
}
var_dump(count($results), array_sum((array) $results));
?>
--EXPECT--
int(8)
int(160)

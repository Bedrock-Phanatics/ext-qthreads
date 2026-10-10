--TEST--
Test ThreadSafeQueue value lifetimes when the pushing thread drops its refs or exits
--FILE--
<?php

use pmmp\thread\ConnectionException;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\ThreadSafeQueue;

class Pusher extends Thread{
	public function __construct(private ThreadSafeQueue $q, private bool $wait){}

	public function run() : void{
		$s = str_repeat("s", 10);
		$this->q->push($s);
		$this->q->push(ThreadSafeArray::fromArray(["v" => str_repeat("a", 5)]));
		$this->q->push(function() use ($s) : string{ return $s; });
		unset($s);

		if($this->wait){
			//stay alive, without any refs of our own, until everything was popped
			$this->q->synchronized(function() : void{
				while(count($this->q) > 0){
					$this->q->wait();
				}
			});
		}
	}
}

function drain(ThreadSafeQueue $q) : void{
	for($i = 0; $i < 3; $i++){
		try{
			$v = $q->pop(0);
			echo match(true){
				is_string($v) => "string $v",
				$v instanceof ThreadSafeArray => "array " . $v["v"],
				$v instanceof \Closure => "closure " . $v(),
				default => "unexpected " . gettype($v),
			}, "\n";
		}catch(ConnectionException $e){
			echo get_class($e), "\n";
		}
	}
	$q->synchronized(fn() => $q->notify());
}

echo "--- pusher alive\n";
$q = new ThreadSafeQueue();
$t = new Pusher($q, true);
$t->start(Thread::INHERIT_ALL);
while(count($q) < 3){
	usleep(1000);
}
//churn the pusher's heap so stale pointers would show
drain($q);
$t->join();

echo "--- pusher exited\n";
$q = new ThreadSafeQueue();
$t = new Pusher($q, false);
$t->start(Thread::INHERIT_ALL);
$t->join();
unset($t);
drain($q);

echo "--- queue destroyed with values still in it\n";
for($i = 0; $i < 100; $i++){
	$q = new ThreadSafeQueue();
	$q->push(str_repeat("x", $i));
	$q->push(ThreadSafeArray::fromArray([1, 2]));
	$q->push(fn() => $i);
	unset($q);
}
echo "OK\n";
?>
--EXPECT--
--- pusher alive
string ssssssssss
array aaaaa
closure ssssssssss
--- pusher exited
string ssssssssss
pmmp\thread\ConnectionException
pmmp\thread\ConnectionException
--- queue destroyed with values still in it
OK

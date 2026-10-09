--TEST--
Test that thread state transitions and monitor waits stay consistent under repeated concurrent start/join
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\Worker;

class StateThread extends Thread{
	public function __construct(private ThreadSafe $gate){}

	public function run() : void{
		$this->gate->synchronized(function() : void{
			while(!$this->gate["open"]){
				$this->gate->wait();
			}
		});
	}
}

$errors = 0;
for($round = 0; $round < 20; $round++){
	$gate = new \pmmp\thread\ThreadSafeArray();
	$gate["open"] = false;

	$threads = [];
	for($i = 0; $i < 8; $i++){
		$t = new StateThread($gate);
		if($t->isStarted() || $t->isJoined()){
			$errors++;
		}
		$t->start(Thread::INHERIT_NONE);
		if(!$t->isStarted() || $t->isJoined()){
			$errors++;
		}
		$threads[] = $t;
	}

	$gate->synchronized(function() use ($gate) : void{
		$gate["open"] = true;
		$gate->notify();
	});

	foreach($threads as $t){
		$t->join();
		if(!$t->isJoined() || $t->isRunning() || $t->isTerminated()){
			$errors++;
		}
	}
}

for($round = 0; $round < 10; $round++){
	$w = new Worker();
	$w->start(Thread::INHERIT_NONE);
	$w->shutdown();
	if(!$w->isJoined()){
		$errors++;
	}
}

$ts = new ThreadSafe();
$start = hrtime(true);
$ts->synchronized(fn() => $ts->wait(10000));
if((hrtime(true) - $start) < 9_000_000){
	$errors++;
}

//threads destroyed without an explicit join must still be joined safely
for($i = 0; $i < 10; $i++){
	$gate = new \pmmp\thread\ThreadSafeArray();
	$gate["open"] = true;
	$t = new StateThread($gate);
	$t->start(Thread::INHERIT_NONE);
	unset($t);
}

var_dump($errors);
?>
--EXPECT--
int(0)

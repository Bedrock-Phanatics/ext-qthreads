--TEST--
Test that a crashed Worker with queued tasks can still be collected and shut down, and rejects new work
--FILE--
<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\Worker;

class Ok extends Runnable{
	public function run() : void{}
}

class Crash extends Runnable{
	public function run() : void{
		throw new \Exception("crash");
	}
}

$w = new Worker();
$w->start(Thread::INHERIT_ALL);
$w->stack(new Ok());
$w->stack(new Crash());
for($i = 0; $i < 10; $i++){
	try{
		$w->stack(new Ok());
	}catch(\RuntimeException $e){
		//the worker may already have crashed
	}
}

//wait until the crash is visible to the parent
$w->synchronized(function() use ($w) : void{
	while(!$w->isTerminated() && $w->collect() > 0){
		$w->wait();
	}
});
while(!$w->isTerminated()){
	usleep(1000);
}

try{
	$w->stack(new Ok());
	echo "accepted task after crash\n";
}catch(\RuntimeException $e){
	echo $e->getMessage() . "\n";
}

$w->shutdown();
$w->collect();
echo "done\n";
?>
--EXPECTF--
Fatal error: Uncaught Exception: crash in %s:%d
Stack trace:
#0 [internal function]: Crash->run()
#1 {main}
  thrown in %s on line %d
this pmmp\thread\Worker is no longer running and cannot accept tasks
done

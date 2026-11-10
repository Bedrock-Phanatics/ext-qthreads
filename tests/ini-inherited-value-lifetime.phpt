--TEST--
Test that inherited ini values don't point into the creator's memory
--FILE--
<?php

use pmmp\thread\Thread;

ini_set("date.timezone", implode("/", ["Europe", "Paris"]));

$thread = new class extends Thread{
	public function run() : void{
		$this->synchronized(function() : void{
			while(!isset($this->changed)){
				$this->wait();
			}
		});
		var_dump(date_default_timezone_get());
	}
};
$thread->start(Thread::INHERIT_ALL);

//frees the value the thread inherited and reuses its memory
ini_set("date.timezone", "UTC");
$junk = [];
for($i = 0; $i < 1000; $i++){
	$junk[] = str_repeat(chr(65 + $i % 26), 12);
}
$thread->synchronized(function() use ($thread) : void{
	$thread->changed = true;
	$thread->notify();
});
$thread->join();
?>
--EXPECT--
string(12) "Europe/Paris"

--TEST--
Test an error handler running while an anonymous class links can wait on a thread copying another of our anonymous classes
--DESCRIPTION--
Linking can call error handlers (and autoloaders), so threads copying our unlinked anonymous classes must never have
to wait for us to finish linking one.
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeQueue;

class Base{}

class Copier extends Thread{
	public function __construct(private ThreadSafeQueue $in, private ThreadSafeQueue $out){}

	public function run() : void{
		while(($make = $this->in->pop()) !== null){
			$this->out->push($make() instanceof \Base ? "copied" : "wrong class");
		}
	}
}

$in = new ThreadSafeQueue();
$out = new ThreadSafeQueue();
$copier = new Copier($in, $out);
$copier->start(Thread::INHERIT_ALL);

//declared after the copier started, so it has to copy them lazily
$make = eval('return static function() : object{ return new class extends \Base{}; };');

set_error_handler(function(int $errno, string $message) use ($in, $out, $make) : bool{
	$in->push($make);
	//bounded so a regression fails instead of hanging
	echo "handler: ", $out->pop(5_000_000) ?? "timed out", "\n";
	return true;
});

//the missing return type is deprecated, which is reported while linking
eval('$o = new class implements \IteratorAggregate{ public function getIterator(){ return new \ArrayIterator([]); } };');
restore_error_handler();

$in->close();
$copier->join();
echo "OK\n";
?>
--EXPECT--
handler: copied
OK

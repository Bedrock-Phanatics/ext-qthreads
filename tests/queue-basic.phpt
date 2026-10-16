--TEST--
Test ThreadSafeQueue basic behaviour and value types
--FILE--
<?php

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use pmmp\thread\ThreadSafeQueue;

enum Suit{
	case Hearts;
}

class Box extends ThreadSafe{
	public int $v = 1;
}

$q = new ThreadSafeQueue();
var_dump($q instanceof ThreadSafe, count($q), $q->pop(0));

$box = new Box();
foreach([1, 1.5, true, false, "string", str_repeat("x", 3), [1, [2]], Suit::Hearts, $box, static fn() : string => "closure"] as $value){
	$q->push($value);
}
var_dump(count($q));
var_dump($q->pop(), $q->pop(), $q->pop(), $q->pop(), $q->pop(), $q->pop());
$array = $q->pop();
var_dump($array instanceof ThreadSafeArray, $array[1][0]);
var_dump($q->pop(), $q->pop() === $box, ($q->pop())());
var_dump(count($q), $q->pop(0));

//timeouts
$start = hrtime(true);
var_dump($q->pop(20000));
var_dump(hrtime(true) - $start >= 15_000_000);

//close: queued values can still be popped, then pop() returns null without waiting
$q->push("a");
$q->push("b");
$q->close();
var_dump($q->isClosed(), $q->pop(), $q->pop(), $q->pop(), $q->pop(1000000));

$errors = [
	fn() => $q->push("c"),
	fn() => (new ThreadSafeQueue())->push(null),
	fn() => (new ThreadSafeQueue())->push(new \stdClass()),
	fn() => (new ThreadSafeQueue())->push([new \stdClass()]),
	fn() => (new ThreadSafeQueue())->pop(-1),
	function() use ($q) : void{ foreach($q as $v){} },
	function() use ($q) : void{ $q->prop = 1; },
];
foreach($errors as $error){
	try{
		$error();
	}catch(\Throwable $e){
		echo get_class($e), ": ", $e->getMessage(), "\n";
	}
}

//a failed push doesn't leave anything behind
$q = new ThreadSafeQueue();
try{
	$q->push([1, new \stdClass()]);
}catch(\Throwable $e){}
var_dump(count($q), $q->pop(0));
?>
--EXPECT--
bool(true)
int(0)
NULL
int(10)
int(1)
float(1.5)
bool(true)
bool(false)
string(6) "string"
string(3) "xxx"
bool(true)
int(2)
enum(Suit::Hearts)
bool(true)
string(7) "closure"
int(0)
NULL
NULL
bool(true)
bool(true)
string(1) "a"
string(1) "b"
NULL
NULL
RuntimeException: Cannot push to a closed pmmp\thread\ThreadSafeQueue
ValueError: pmmp\thread\ThreadSafeQueue::push(): Argument #1 ($value) cannot be null
pmmp\thread\NonThreadSafeValueError: Cannot push non-thread-safe value of type stdClass to pmmp\thread\ThreadSafeQueue
pmmp\thread\NonThreadSafeValueError: Cannot merge non-thread-safe value of type stdClass (input key 0) into pmmp\thread\ThreadSafeArray
ValueError: pmmp\thread\ThreadSafeQueue::pop(): Argument #1 ($timeout) must be greater than or equal to 0
Error: pmmp\thread\ThreadSafeQueue cannot be iterated, use pop() instead
Error: Cannot create dynamic property pmmp\thread\ThreadSafeQueue::$prop
int(0)
NULL

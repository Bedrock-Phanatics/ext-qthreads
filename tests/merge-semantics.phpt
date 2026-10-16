--TEST--
Test ThreadSafeArray::merge() and fromArray() semantics across value and key types
--FILE--
<?php

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

enum Suit{
	case Hearts;
}

class Box extends ThreadSafe{
	public int $v = 7;
}

//overwrite true/false, int and string keys
$a = ThreadSafeArray::fromArray([0 => "zero", "k" => "old", 5 => 5]);
$a->merge([0 => "new", "k" => "new", "n" => 1], false);
var_dump($a[0], $a["k"], $a["n"]);
$a->merge([0 => "new", "k" => "new"]);
var_dump($a[0], $a["k"]);

//self merge is a no-op
$before = (array) $a;
var_dump($a->merge($a), (array) $a == $before);

//every supported value type, array -> ThreadSafeArray -> ThreadSafeArray
$box = new Box();
$src = ThreadSafeArray::fromArray([
	"int" => 1,
	"float" => 1.5,
	"bool" => true,
	"null" => null,
	"string" => str_repeat("s", 3),
	"enum" => Suit::Hearts,
	"box" => $box,
	"closure" => static fn() : string => "called",
	"nested" => [1, [2]],
]);
$dst = new ThreadSafeArray();
$dst->merge($src);
var_dump(
	$dst["int"], $dst["float"], $dst["bool"], $dst["null"], $dst["string"], $dst["enum"],
	$dst["box"] === $box, ($dst["closure"])(), $dst["nested"][1][0]
);

//unsupported value partway through: earlier keys are kept, the rest are not merged
$partial = new ThreadSafeArray();
try{
	$partial->merge(["first" => 1, "bad" => new \stdClass(), "last" => 3]);
}catch(\pmmp\thread\NonThreadSafeValueError $e){
	echo $e->getMessage(), "\n";
}
var_dump(isset($partial["first"]), isset($partial["bad"]), isset($partial["last"]));

//objects: properties are merged
$obj = new \stdClass();
$obj->x = 1;
$obj->y = "y";
$fromObj = new ThreadSafeArray();
$fromObj->merge($obj);
var_dump($fromObj["x"], $fromObj["y"]);

//large arrays keep order and contents
$big = [];
for($i = 0; $i < 20000; $i++){
	$big[$i % 2 === 0 ? $i : "k$i"] = $i;
}
$large = ThreadSafeArray::fromArray($big);
$copy = new ThreadSafeArray();
$copy->merge($large);
var_dump(count($large), count($copy), (array) $copy === $big);

//merging into a non-empty array still appends correctly
$grow = ThreadSafeArray::fromArray([1, 2]);
$grow->merge([5 => "five", "x" => "x"]);
$grow[] = "next";
foreach($grow as $k => $v){
	echo "$k => $v\n";
}
?>
--EXPECT--
string(4) "zero"
string(3) "old"
int(1)
string(3) "new"
string(3) "new"
bool(true)
bool(true)
int(1)
float(1.5)
bool(true)
NULL
string(3) "sss"
enum(Suit::Hearts)
bool(true)
string(6) "called"
int(2)
Cannot merge non-thread-safe value of type stdClass (input key "bad") into pmmp\thread\ThreadSafeArray
bool(true)
bool(false)
bool(false)
int(1)
string(1) "y"
int(20000)
int(20000)
bool(true)
0 => 1
1 => 2
5 => five
x => x
6 => next

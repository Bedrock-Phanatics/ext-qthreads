--TEST--
Test that empty() on ThreadSafe objects with __isset() also checks the value returned by __get()
--FILE--
<?php

class Magic extends \pmmp\thread\ThreadSafe{
	public function __isset($name) : bool{
		return true;
	}

	public function __get($name){
		return match($name){
			"zero" => 0,
			"emptyString" => "",
			"null" => null,
			default => "value",
		};
	}
}

$m = new Magic();
var_dump(empty($m->zero));
var_dump(empty($m->emptyString));
var_dump(empty($m->null));
var_dump(empty($m->other));
var_dump(isset($m->zero));
var_dump(isset($m->null));

//no __get: isset() true from __isset() means not empty is unknown, so empty() must be true like for normal objects
class IssetOnly extends \pmmp\thread\ThreadSafe{
	public function __isset($name) : bool{
		return true;
	}
}
$n = new IssetOnly();
var_dump(empty($n->anything));

//normal objects for comparison
class Plain{
	public function __isset($name) : bool{ return true; }
	public function __get($name){ return $name === "zero" ? 0 : "value"; }
}
$p = new Plain();
var_dump(empty($p->zero), empty($p->other));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)

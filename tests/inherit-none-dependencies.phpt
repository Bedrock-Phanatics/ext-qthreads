--TEST--
Test that code copied with INHERIT_NONE can use functions and anonymous classes it depends on
--DESCRIPTION--
The compiler emits INIT_FCALL and DECLARE_ANON_CLASS only for targets known at compile time, and the VM doesn't check
them at runtime, so a copied method referring to a function or anonymous class that wasn't inherited used to crash.
--FILE--
<?php

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

function helper(int $n) : string{
	return "helper" . isEven($n);
}
function isEven(int $n) : string{
	return $n === 0 ? "!" : isOdd($n - 1);
}
function isOdd(int $n) : string{
	return $n === 0 ? "?" : isEven($n - 1);
}

class SameFile{
	public static function hi() : string{
		return "same file";
	}
}

class Worker1 extends Thread{
	public function __construct(private ThreadSafeArray $out){}

	public function run() : void{
		echo helper(4), "\n";

		$o = new class{
			public string $x = "anonymous";

			public function inner() : string{
				return (new class{ public string $y = "nested anonymous"; })->y;
			}
		};
		echo $o->x, " ", $o->inner(), "\n";

		$f = function() : string{
			return (function() : string{
				return "nested closure " . helper(1);
			})();
		};
		echo $f(), "\n";

		//not inherited and no autoloader: a normal error, or found if OPcache shared it
		try{
			echo SameFile::hi(), "\n";
		}catch(\Error $e){
			echo "same file\n";
		}

		//closures sent from the parent may also depend on functions
		echo ($this->out["fn"])(), "\n";
	}
}

$out = new ThreadSafeArray();
$out["fn"] = static fn() : string => "sent closure " . helper(2);
$t = new Worker1($out);
$t->start(Thread::INHERIT_NONE);
$t->join();
echo "parent ok\n";
?>
--EXPECT--
helper!
anonymous nested anonymous
nested closure helper?
same file
sent closure helper!
parent ok

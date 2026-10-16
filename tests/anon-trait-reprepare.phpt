--TEST--
Test that an anonymous class copied before it was linked works once linked, including trait aliases and visibility
--DESCRIPTION--
The Worker copies the anonymous class when it starts, before the parent links it. When the linked class arrives with
the task, its trait methods must be scoped to the worker's copy, and the previous trait info must not leak.
--FILE--
<?php

trait T{
	public function hello() : string{ return "hello"; }
	public function bye() : string{ return "bye"; }
}

trait U{
	public function hello() : string{ return "u-hello"; }
}

function make() : \pmmp\thread\Runnable{
	return new class extends \pmmp\thread\Runnable{
		use T, U{
			T::hello insteadof U;
			U::hello as uHello;
			T::bye as protected farewell;
		}

		public function run() : void{
			echo $this->hello(), " ", $this->uHello(), " ", $this->farewell(), "\n";
		}
	};
}

$w = new \pmmp\thread\Worker();
$w->start(\pmmp\thread\Thread::INHERIT_ALL);
$w->stack(make());
$w->stack(make());
$w->shutdown();
echo "ok\n";
?>
--EXPECT--
hello u-hello bye
hello u-hello bye
ok

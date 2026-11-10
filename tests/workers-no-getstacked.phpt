--TEST--
Test pthreads workers rules (getStacked)
--DESCRIPTION--
This test verifies that workers cannot be misused (getStacked)
--FILE--
<?php
class Test extends \pmmp\thread\Worker {
	public function run() : void{
		$this->getStacked();
	}
}

$worker = new Test();
$worker->start(\pmmp\thread\Thread::INHERIT_ALL);
$worker->shutdown();
?>
--EXPECTF--
Fatal error: Uncaught RuntimeException: only the creator of this Test may call getStacked in %s:%d
Stack trace:
#0 %s(%d): pmmp\thread\Worker->getStacked()
#1 [internal function]: Test->run()
#2 {main}
  thrown in %s on line %d

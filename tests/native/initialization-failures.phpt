--TEST--
Failed shared-object and startup-monitor initialization can be retried safely
--SKIPIF--
<?php
if (getenv('PMMPTHREAD_FAULT_INJECTION') !== '1') die('skip requires fault-injection build');
?>
--FILE--
<?php
class InitializationThread extends \pmmp\thread\Thread {
	public string $value = 'default';
	public function run() : void {}
}
class InitializationWorker extends \pmmp\thread\Worker {
	public string $value = 'default';
}
foreach ([\pmmp\thread\ThreadSafe::class, \pmmp\thread\ThreadSafeArray::class, InitializationThread::class, InitializationWorker::class] as $class) {
	foreach (['calloc', 'attr', 'type', 'mutex', 'cond'] as $operation) {
		putenv("PMMPTHREAD_TEST_FAIL=$operation");
		try {
			new $class();
			throw new Exception("$class did not reject $operation failure");
		} catch (Error $error) {
			if ($error->getMessage() !== 'Cannot initialize thread-safe object') throw $error;
		}
		$object = new $class();
		unset($object);
		gc_collect_cycles();
	}
}
foreach ([InitializationThread::class, InitializationWorker::class] as $class) {
	foreach (['attr', 'type', 'mutex', 'cond'] as $operation) {
		$thread = new $class();
		putenv("PMMPTHREAD_TEST_FAIL=$operation");
		try {
			$thread->start(\pmmp\thread\Thread::INHERIT_ALL);
			throw new Exception("Thread started despite $operation failure");
		} catch (RuntimeException $error) {
			if ($error->getMessage() !== 'Cannot initialize thread startup monitor') throw $error;
		}
		if ($thread->isStarted()) throw new Exception('Failed start changed monitor state');
		if (!$thread->start(\pmmp\thread\Thread::INHERIT_ALL) || !$thread->join()) throw new Exception('Retry failed');
		unset($thread);
	}
}
echo "OK\n";
?>
--EXPECT--
OK

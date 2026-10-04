--TEST--
Repeated monitor creation, cross-thread connections, wait/notify and implicit joins
--FILE--
<?php
class MonitorLifecycleThread extends \pmmp\thread\Thread {
	public function __construct(public \pmmp\thread\ThreadSafeArray $shared) {}
	public function run() : void {
		$this->shared->synchronized(function() : void {
			$this->shared['ready'] = true;
			$this->shared->notify();
			while (!$this->shared['exit']) $this->shared->wait();
		});
	}
}
for ($i = 0; $i < 100; $i++) {
	$shared = \pmmp\thread\ThreadSafeArray::fromArray(['ready' => false, 'exit' => false]);
	$thread = new MonitorLifecycleThread($shared);
	if (!$thread->start(\pmmp\thread\Thread::INHERIT_ALL)) throw new Exception('Start failed');
	$shared->synchronized(function() use ($shared) : void {
		while (!$shared['ready']) {
			if (!$shared->wait(5000000)) throw new Exception('Ready timeout');
		}
		$shared['exit'] = true;
		$shared->notify();
	});
	if ($i % 2 === 0 && !$thread->join()) throw new Exception('Join failed');
	unset($thread, $shared);
	$worker = new \pmmp\thread\Worker();
	if (!$worker->start(\pmmp\thread\Thread::INHERIT_ALL) || !$worker->shutdown()) throw new Exception('Idle worker failed');
	unset($worker);
	gc_collect_cycles();
}
echo "OK\n";
?>
--EXPECT--
OK

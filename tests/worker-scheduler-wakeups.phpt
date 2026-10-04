--TEST--
Worker queue wakeups reach the scheduler with competing public waiters
--FILE--
<?php
class SchedulerWorker extends \pmmp\thread\Worker {
	public int $waitersReady = 0;
}
class SchedulerWaiter extends \pmmp\thread\Thread {
	public function __construct(public SchedulerWorker $worker) {}
	public function run() : void {
		$this->worker->synchronized(function() : void {
			$this->worker->waitersReady++;
			$this->worker->notify();
			while (!$this->worker->isShutdown()) $this->worker->wait();
		});
	}
}
class SchedulerTask extends \pmmp\thread\Runnable {
	public function __construct(public \pmmp\thread\ThreadSafeArray $completed) {}
	public function run() : void {
		$this->completed->synchronized(function() : void {
			$this->completed['count'] = $this->completed['count'] + 1;
			$this->completed->notify();
		});
	}
}
$worker = new SchedulerWorker();
$worker->start(\pmmp\thread\Thread::INHERIT_ALL);
$waiters = [];
for ($i = 0; $i < 8; $i++) {
	$waiter = new SchedulerWaiter($worker);
	$waiter->start(\pmmp\thread\Thread::INHERIT_ALL);
	$waiters[] = $waiter;
}
$worker->synchronized(function() use ($worker) : void {
	while ($worker->waitersReady < 8) {
		if (!$worker->wait(5000000) && $worker->waitersReady < 8) throw new Exception('Waiter startup timeout');
	}
});
$completed = \pmmp\thread\ThreadSafeArray::fromArray(['count' => 0]);
$submitted = 0;
foreach (array_merge(array_fill(0, 100, 1), [128]) as $batch) {
	for ($i = 0; $i < $batch; $i++) {
		$worker->stack(new SchedulerTask($completed));
		$submitted++;
	}
	$completed->synchronized(function() use ($completed, $submitted) : void {
		while ($completed['count'] < $submitted) {
			if (!$completed->wait(5000000) && $completed['count'] < $submitted) throw new Exception('Task wakeup timeout');
		}
	});
	$worker->synchronized(function() use ($worker) : void {
		while ($worker->collect() > 0) {
			if (!$worker->wait(5000000) && $worker->collect() > 0) throw new Exception('Collection timeout');
		}
	});
}
if (!$worker->shutdown() || $worker->getStacked() !== 0 || $completed['count'] !== $submitted) throw new Exception('Shutdown failed');
foreach ($waiters as $waiter) $waiter->join();
echo "OK\n";
?>
--EXPECT--
OK

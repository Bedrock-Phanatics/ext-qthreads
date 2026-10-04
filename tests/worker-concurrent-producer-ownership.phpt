--TEST--
Concurrent non-creator Worker producers are rejected without corrupting its queue
--FILE--
<?php
class ProducerOwnershipTask extends \pmmp\thread\Runnable {
	public function run() : void {}
}
class ProducerOwnershipThread extends \pmmp\thread\Thread {
	public int $rejected = 0;
	public function __construct(public \pmmp\thread\Worker $worker) {}
	public function run() : void {
		try {
			$this->worker->getStacked();
			throw new Exception('Non-creator read the creator-owned queue');
		} catch (RuntimeException $error) {
			if (!str_contains($error->getMessage(), 'may call getStacked')) throw $error;
		}
		for ($i = 0; $i < 100; $i++) {
			try {
				$this->worker->stack(new ProducerOwnershipTask());
				throw new Exception('Non-creator submitted a task');
			} catch (RuntimeException $error) {
				if (!str_contains($error->getMessage(), 'may call stack')) throw $error;
				$this->rejected++;
			}
		}
	}
}
$worker = new \pmmp\thread\Worker();
$worker->start(\pmmp\thread\Thread::INHERIT_ALL);
$producers = [];
for ($i = 0; $i < 8; $i++) {
	$producer = new ProducerOwnershipThread($worker);
	$producer->start(\pmmp\thread\Thread::INHERIT_ALL);
	$producers[] = $producer;
}
for ($i = 0; $i < 100; $i++) $worker->stack(new ProducerOwnershipTask());
foreach ($producers as $producer) {
	$producer->join();
	if ($producer->rejected !== 100) throw new Exception('Missing rejection');
}
$worker->synchronized(function() use ($worker) : void {
	while ($worker->collect() > 0) {
		if (!$worker->wait(5000000) && $worker->collect() > 0) throw new Exception('Collection timeout');
	}
});
if (!$worker->shutdown() || $worker->getStacked() !== 0) throw new Exception('Shutdown failed');
echo "OK\n";
?>
--EXPECT--
OK

<?php

use pmmp\thread\Runnable;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\Worker;

class BenchmarkWorker extends Worker {
	public int $ready = 0;
	public bool $stop = false;
}

class BenchmarkWaiter extends Thread {
	public function __construct(public BenchmarkWorker $worker) {}
	public function run() : void {
		$this->worker->synchronized(function() : void {
			$this->worker->ready++;
			$this->worker->notify();
			while (!$this->worker->stop) $this->worker->wait();
		});
	}
}

class BenchmarkCompletion extends ThreadSafe {
	public int $count = 0;
	public int $latency = 0;
}

class BenchmarkTask extends Runnable {
	public int $sent = 0;
	public function __construct(public ?BenchmarkCompletion $completion = null) {}
	public function run() : void {
		if ($this->completion !== null) {
			$latency = hrtime(true) - $this->sent;
			$this->completion->synchronized(function() use ($latency) : void {
				$this->completion->latency = $latency;
				$this->completion->count++;
				$this->completion->notify();
			});
		}
	}
}

function collectAll(Worker $worker) : void {
	$worker->synchronized(function() use ($worker) : void {
		while ($worker->collect() > 0) {
			if (!$worker->wait(5000000) && $worker->collect() > 0) throw new RuntimeException('Collection timeout');
		}
	});
}

function cpuSeconds(array $usage) : float {
	return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
		+ $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
}

$mode = $argv[1] ?? 'throughput';
$count = (int) ($argv[2] ?? 20000);
$waiterCount = (int) ($argv[3] ?? 0);
if (!in_array($mode, ['throughput', 'handoff', 'idle'], true) || $count < 1 || $waiterCount < 0) {
	throw new InvalidArgumentException('Usage: worker.php throughput|handoff|idle count public-waiters');
}

$workers = [];
$waiters = [];
for ($i = 0; $i < ($mode === 'idle' ? $count : 1); $i++) {
	$worker = new BenchmarkWorker();
	$worker->start(Thread::INHERIT_ALL);
	$workers[] = $worker;
}
for ($i = 0; $i < $waiterCount; $i++) {
	$waiter = new BenchmarkWaiter($worker);
	$waiter->start(Thread::INHERIT_ALL);
	$waiters[] = $waiter;
}
$worker->synchronized(function() use ($worker, $waiterCount) : void {
	while ($worker->ready < $waiterCount) {
		if (!$worker->wait(5000000) && $worker->ready < $waiterCount) throw new RuntimeException('Waiter startup timeout');
	}
});
for ($i = 0; $i < 1000; $i++) $worker->stack(new BenchmarkTask());
collectAll($worker);
usleep(100000);
$completion = new BenchmarkCompletion();
$latencies = [];
$before = getrusage();
$start = hrtime(true);
$submitSeconds = 0.0;
if ($mode === 'idle') {
	usleep(1000000);
} elseif ($mode === 'throughput') {
	for ($i = 0; $i < $count; $i++) $worker->stack(new BenchmarkTask());
	$submitSeconds = (hrtime(true) - $start) / 1e9;
	collectAll($worker);
} else {
	for ($i = 1; $i <= $count; $i++) {
		$task = new BenchmarkTask($completion);
		$task->sent = hrtime(true);
		$worker->stack($task);
		$completion->synchronized(function() use ($completion, $i) : void {
			while ($completion->count < $i) {
				if (!$completion->wait(5000000) && $completion->count < $i) throw new RuntimeException('Handoff timeout');
			}
		});
		$latencies[] = $completion->latency;
		collectAll($worker);
	}
}
$seconds = (hrtime(true) - $start) / 1e9;
$after = getrusage();
sort($latencies);
$result = [
	'mode' => $mode,
	'count' => $count,
	'public_waiters' => $waiterCount,
	'wall_seconds' => $seconds,
	'submission_seconds' => $submitSeconds,
	'cpu_seconds' => cpuSeconds($after) - cpuSeconds($before),
	'peak_rss_kib' => $after['ru_maxrss'],
	'voluntary_context_switches' => $after['ru_nvcsw'] - $before['ru_nvcsw'],
	'involuntary_context_switches' => $after['ru_nivcsw'] - $before['ru_nivcsw'],
];
foreach ([50, 95, 99] as $percentile) {
	$result['latency_p' . $percentile . '_ns'] = $latencies === [] ? null : $latencies[(int) ceil(count($latencies) * $percentile / 100) - 1];
}
$worker->synchronized(function() use ($worker) : void {
	$worker->stop = true;
	$worker->notify();
});
foreach ($waiters as $waiter) $waiter->join();
foreach ($workers as $worker) $worker->shutdown();
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";

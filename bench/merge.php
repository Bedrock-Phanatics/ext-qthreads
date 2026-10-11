<?php

declare(strict_types=1);

require __DIR__ . "/common.php";

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

function source(int $n) : array{
	$src = [];
	for($i = 0; $i < $n; $i++){
		$src[$i % 3 === 0 ? "k$i" : $i] = $i % 2 === 0 ? $i : "value$i";
	}
	return $src;
}

[$case, $n] = [$argv[1] ?? "", (int) ($argv[2] ?? 0)];
if($n < 1){
	fwrite(STDERR, "usage: merge.php <merge|fromArray|tsa-merge|concurrent> <elements>\n");
	exit(1);
}
$src = source($n);
$tsaSrc = ThreadSafeArray::fromArray($src);
$reps = intdiv(max(200_000, $n * 20), $n);

$before = usage();
$start = hrtime(true);
switch($case){
	case "merge":
		for($r = 0; $r < $reps; $r++){
			(new ThreadSafeArray())->merge($src);
		}
		break;
	case "fromArray":
		for($r = 0; $r < $reps; $r++){
			ThreadSafeArray::fromArray($src);
		}
		break;
	case "tsa-merge":
		for($r = 0; $r < $reps; $r++){
			(new ThreadSafeArray())->merge($tsaSrc);
		}
		break;
	case "concurrent":
		$dst = new ThreadSafeArray();
		$threads = [];
		for($i = 0; $i < 4; $i++){
			$threads[] = $t = new class($dst, $n, $reps) extends Thread{
				public function __construct(private ThreadSafeArray $dst, private int $n, private int $reps){}

				public function run() : void{
					$src = source($this->n);
					for($r = 0; $r < $this->reps; $r++){
						$this->dst->merge($src);
					}
				}
			};
			$t->start(Thread::INHERIT_ALL);
		}
		foreach($threads as $t){
			$t->join();
		}
		$reps *= 4;
		break;
	default:
		fwrite(STDERR, "unknown case $case\n");
		exit(1);
}
report("$case-$n", ["ns_elem" => (hrtime(true) - $start) / ($reps * $n)], $before);

<?php

declare(strict_types=1);

require __DIR__ . "/common.php";

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

class Box extends ThreadSafe{
	public int $v = 1;
}

$case = $argv[1] ?? "";
$before = usage();
$start = hrtime(true);

switch($case){
	case "rw-int":
	case "rw-string":
		$a = new ThreadSafeArray();
		$value = $case === "rw-int" ? 1 : str_repeat("x", 64);
		$n = 2_000_000;
		for($i = 0; $i < $n; $i++){
			$a["k"] = $value;
			$x = $a["k"];
		}
		break;

	case "synchronized":
		$a = new ThreadSafeArray();
		$n = 2_000_000;
		for($i = 0; $i < $n; $i++){
			$a->synchronized(static fn() => null);
		}
		break;

	case "create":
		$n = 1_000_000;
		$keep = [];
		for($i = 0; $i < $n; $i++){
			$keep[] = new Box();
			if(count($keep) === 1000){
				$keep = [];
			}
		}
		break;

	case "connect":
		//another thread connects to objects created here
		$src = new ThreadSafeArray();
		$keep = [];
		for($i = 0; $i < 20000; $i++){
			$keep[] = $b = new Box();
			$src[] = $b;
		}
		$before = usage();
		$start = hrtime(true);
		$t = new class($src) extends Thread{
			public function __construct(private ThreadSafeArray $src){}

			public function run() : void{
				for($r = 0; $r < 10; $r++){
					foreach($this->src as $b){
						$b->v;
					}
				}
			}
		};
		$t->start(Thread::INHERIT_ALL);
		$t->join();
		$n = 200_000;
		break;

	case "string-transfer":
	case "closure-transfer":
		$closures = $case === "closure-transfer";
		$n = $closures ? 20_000 : 200_000;
		$a = new ThreadSafeArray();
		$t = new class($a, $n) extends Thread{
			public function __construct(private ThreadSafeArray $a, private int $n){}

			public function run() : void{
				for($i = 0; $i < $this->n; $i++){
					$v = $this->a->synchronized(function() : mixed{
						while($this->a->count() === 0){
							$this->a->wait();
						}
						return $this->a->shift();
					});
					if($v instanceof \Closure){
						$v();
					}
				}
			}
		};
		$t->start(Thread::INHERIT_ALL);
		$s = str_repeat("x", 1024);
		for($i = 0; $i < $n; $i++){
			$v = $closures ? static fn() => $i + 1 : $s;
			$a->synchronized(function() use ($a, $v) : void{
				$a[] = $v;
				$a->notify();
			});
		}
		$t->join();
		break;

	default:
		fwrite(STDERR, "usage: shared.php <rw-int|rw-string|synchronized|create|connect|string-transfer|closure-transfer>\n");
		exit(1);
}

report($case, ["ns_op" => (hrtime(true) - $start) / $n], $before);

<?php

declare(strict_types=1);

require __DIR__ . "/common.php";

use pmmp\thread\Thread;

$case = $argv[1] ?? "";
$options = match($case){
	"all" => Thread::INHERIT_ALL,
	"none" => Thread::INHERIT_NONE,
	default => null,
};
if($options === null){
	fwrite(STDERR, "usage: startup.php <all|none>\n");
	exit(1);
}

//enough user code in scope that copying it dominates
for($i = 0; $i < 300; $i++){
	eval("function f$i(int \$x) : int{ return \$x + $i; }
	class C$i{
		public array \$a = [1, 2, 3];
		public function m(int \$x) : int{ \$y = f$i(\$x); for(\$j = 0; \$j < 3; \$j++){ \$y += strlen((string) \$j); } return \$y; }
		public static function s() : string{ return str_repeat('x', 3); }
	}");
}

$n = 200;
$before = usage();
$start = hrtime(true);
for($i = 0; $i < $n; $i++){
	$t = new class extends Thread{
		public function run() : void{}
	};
	$t->start($options);
	$t->join();
}
report("startjoin-$case", ["us" => (hrtime(true) - $start) / $n / 1e3], $before);

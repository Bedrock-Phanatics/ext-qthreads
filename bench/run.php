<?php

/**
 * Runs the benchmarks against one or more builds of the extension and compares them.
 *
 *   php bench/run.php --ext=base:/path/to/upstream/pmmpthread.so --ext=new:/path/to/pmmpthread.so
 *       [--php=/path/to/php] [--reps=5] [--only=regex] [--cpus=2-5] [--perf] [--ini="-d opcache.enable_cli=1"]
 *
 * Builds are interleaved on every repetition so drift (thermal, other load) affects them equally.
 * Use release (non-debug) builds of both PHP and the extension for meaningful numbers.
 * --perf adds instructions/cycles/cache-misses from `perf stat` (Linux only).
 */

declare(strict_types=1);

const CASES = [
	["shared.php", "rw-int"],
	["shared.php", "rw-string"],
	["shared.php", "synchronized"],
	["shared.php", "create"],
	["shared.php", "connect"],
	["shared.php", "string-transfer"],
	["shared.php", "closure-transfer"],
	["merge.php", "merge", "10"],
	["merge.php", "merge", "1000"],
	["merge.php", "fromArray", "100"],
	["merge.php", "fromArray", "10000"],
	["merge.php", "tsa-merge", "1000"],
	["merge.php", "concurrent", "100"],
	["worker.php", "burst"],
	["worker.php", "roundtrip"],
	["worker.php", "wake-latency"],
	["worker.php", "idle"],
	["queue.php", "queue", "throughput", "1", "1"],
	["queue.php", "array", "throughput", "1", "1"],
	["queue.php", "queue", "throughput", "4", "4"],
	["queue.php", "array", "throughput", "4", "4"],
	["queue.php", "queue", "latency", "1", "1"],
	["queue.php", "array", "latency", "1", "1"],
	["startup.php", "all"],
	["startup.php", "none"],
	["rss.php", "40"],
];

$opts = getopt("", ["ext:", "php:", "reps:", "only:", "cpus:", "perf", "ini:"]);
$exts = [];
foreach((array) ($opts["ext"] ?? []) as $spec){
	[$label, $path] = array_pad(explode(":", $spec, 2), 2, "");
	if($path === "" || !is_file($path)){
		fwrite(STDERR, "--ext must be label:/path/to/pmmpthread.so ($spec)\n");
		exit(1);
	}
	$exts[$label] = $path;
}
if(count($exts) === 0){
	fwrite(STDERR, "at least one --ext=label:/path/to/pmmpthread.so is required\n");
	exit(1);
}
$php = $opts["php"] ?? PHP_BINARY;
$reps = max(1, (int) ($opts["reps"] ?? 5));
$only = $opts["only"] ?? null;
$perf = isset($opts["perf"]);

/** @var array<string, array<string, array<string, float[]>>> $results case => metric => ext => samples */
$results = [];
foreach(CASES as $case){
	$name = implode(" ", $case);
	if($only !== null && preg_match("/$only/", $name) !== 1){
		continue;
	}
	for($rep = 0; $rep < $reps; $rep++){
		foreach($exts as $label => $path){
			$cmd = [];
			if(isset($opts["cpus"])){
				$cmd = ["taskset", "-c", $opts["cpus"]];
			}
			if($perf){
				array_push($cmd, "perf", "stat", "-x", ",", "-e", "instructions:u,cycles:u,cache-misses:u");
			}
			array_push($cmd, $php, "-n", "-d", "extension=$path");
			foreach(preg_split('/\s+/', trim($opts["ini"] ?? ""), -1, PREG_SPLIT_NO_EMPTY) as $arg){
				$cmd[] = $arg;
			}
			$cmd[] = __DIR__ . "/" . $case[0];
			array_push($cmd, ...array_slice($case, 1));

			$proc = proc_open($cmd, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			if(proc_close($proc) !== 0 || !preg_match('/^(\S+) (.*)$/m', $stdout, $m)){
				fwrite(STDERR, "$label: $name failed\n$stdout$stderr");
				continue;
			}
			$label2 = $m[1];
			preg_match_all('/(\w+)=(-?[\d.]+)/', $m[2], $pairs, PREG_SET_ORDER);
			foreach($pairs as [, $key, $value]){
				$results[$label2][$key][$label][] = (float) $value;
			}
			if($perf){
				foreach(explode("\n", $stderr) as $line){
					$fields = str_getcsv($line, ",", "\"", "");
					if(count($fields) > 2 && is_numeric($fields[0])){
						$results[$label2][explode(":", $fields[2])[0]][$label][] = (float) $fields[0];
					}
				}
			}
		}
	}
}

function median(array $v) : float{
	sort($v);
	$n = count($v);
	return $n % 2 === 1 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

function stdev(array $v) : float{
	if(count($v) < 2){
		return 0.0;
	}
	$mean = array_sum($v) / count($v);
	return sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $v)) / (count($v) - 1));
}

$labels = array_keys($exts);
$baseline = $labels[0];
printf("%-28s %-14s", "case", "metric");
foreach($labels as $label){
	printf(" %22s", "$label median±sd");
}
echo count($labels) > 1 ? "  delta vs $baseline\n" : "\n";
foreach($results as $case => $metrics){
	foreach($metrics as $metric => $byExt){
		printf("%-28s %-14s", $case, $metric);
		foreach($labels as $label){
			$samples = $byExt[$label] ?? [];
			printf(" %22s", count($samples) > 0 ? sprintf("%.1f±%.1f", median($samples), stdev($samples)) : "-");
		}
		foreach(array_slice($labels, 1) as $label){
			$base = median($byExt[$baseline] ?? [0]);
			$other = isset($byExt[$label]) ? median($byExt[$label]) : null;
			printf("  %s", $other !== null && $base != 0 ? sprintf("%+.1f%%", ($other - $base) / abs($base) * 100) : "-");
		}
		echo "\n";
	}
}

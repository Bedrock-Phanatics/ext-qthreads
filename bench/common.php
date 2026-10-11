<?php

declare(strict_types=1);

/**
 * Prints one result line: "<case> key=value ...". bench/run.php parses these.
 *
 * @param array<string, int|float> $metrics
 */
function report(string $case, array $metrics, ?array $usageBefore = null) : void{
	if($usageBefore !== null){
		$usage = usage();
		$metrics["cpu_ms"] = round(($usage["cpu"] - $usageBefore["cpu"]) * 1000, 1);
		$metrics["csw"] = $usage["csw"] - $usageBefore["csw"];
	}
	$metrics["rss_kb"] = rssKb();
	$parts = [];
	foreach($metrics as $key => $value){
		$parts[] = $key . "=" . (is_float($value) ? round($value, 2) : $value);
	}
	echo $case, " ", implode(" ", $parts), "\n";
}

/** @return array{cpu: float, csw: int} for the whole process, including other threads */
function usage() : array{
	$r = getrusage();
	return [
		"cpu" => $r["ru_utime.tv_sec"] + $r["ru_stime.tv_sec"] + ($r["ru_utime.tv_usec"] + $r["ru_stime.tv_usec"]) / 1e6,
		"csw" => $r["ru_nvcsw"] + $r["ru_nivcsw"],
	];
}

function rssKb() : int{
	$status = @file_get_contents("/proc/self/status");
	if($status !== false && preg_match('/^VmRSS:\s+(\d+)/m', $status, $m) === 1){
		return (int) $m[1];
	}
	return getrusage()["ru_maxrss"];
}

/** @param float[] $samples */
function percentile(array $samples, float $p) : float{
	if(count($samples) === 0){
		return 0.0;
	}
	sort($samples);
	return $samples[min(count($samples) - 1, (int) (count($samples) * $p))];
}

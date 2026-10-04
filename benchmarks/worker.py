import argparse
import json
import os
import pathlib
import platform
import statistics
import subprocess
import tempfile

parser = argparse.ArgumentParser(description='Compare optimized pmmpthread builds on Linux.')
parser.add_argument('--php', required=True)
parser.add_argument('--opcache', required=True)
parser.add_argument('--extension', action='append', required=True, metavar='NAME=PATH')
parser.add_argument('--repetitions', type=int, default=7)
parser.add_argument('--perf', action='store_true')
parser.add_argument('--output', type=pathlib.Path, required=True)
args = parser.parse_args()
if args.repetitions < 2:
	parser.error('--repetitions must be at least 2')
extensions = dict(value.split('=', 1) for value in args.extension)
script = pathlib.Path(__file__).with_suffix('.php')
workloads = [('throughput', 20000, 0), ('handoff', 1000, 0), ('handoff', 1000, 8), ('throughput', 20000, 8), ('idle', 16, 0)]
results = {'php': subprocess.check_output([args.php, '-n', '-v'], text=True), 'system': platform.platform(), 'cpu_affinity': sorted(os.sched_getaffinity(0)), 'opcache': args.opcache, 'jit': 0, 'extensions': extensions, 'repetitions': args.repetitions, 'samples': {}, 'summary': {}}
for mode, count, waiters in workloads:
	name = f'{mode}-{count}-{waiters}'
	results['samples'][name] = {variant: [] for variant in extensions}
	for repetition in range(args.repetitions):
		variants = list(extensions)
		if repetition % 2:
			variants.reverse()
		for variant in variants:
			command = [args.php, '-n', '-d', 'extension=' + extensions[variant], '-d', 'zend_extension=' + args.opcache, '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=0', str(script), mode, str(count), str(waiters)]
			with tempfile.TemporaryDirectory(prefix='pmmpthread-perf-') as temporary:
				counters = pathlib.Path(temporary) / 'perf.csv'
				if args.perf:
					command = ['perf', 'stat', '-x,', '-o', str(counters), '-e', 'cycles:u,instructions:u', '--'] + command
				completed = subprocess.run(['timeout', '60', *command], capture_output=True, text=True, check=True)
				sample = json.loads(completed.stdout)
				if args.perf:
					sample['perf'] = {}
					for line in counters.read_text().splitlines():
						columns = line.split(',')
						if len(columns) >= 3 and columns[0].strip().isdigit():
							sample['perf'][columns[2]] = int(columns[0])
				results['samples'][name][variant].append(sample)
	results['summary'][name] = {}
	for variant, samples in results['samples'][name].items():
		results['summary'][name][variant] = {}
		keys = ['wall_seconds', 'submission_seconds', 'cpu_seconds', 'peak_rss_kib', 'voluntary_context_switches', 'involuntary_context_switches', 'latency_p50_ns', 'latency_p95_ns', 'latency_p99_ns']
		for key in keys:
			values = [sample[key] for sample in samples if sample[key] is not None]
			if values:
				results['summary'][name][variant][key] = {'median': statistics.median(values), 'variance': statistics.variance(values)}
		if args.perf:
			for key in samples[0]['perf']:
				values = [sample['perf'][key] for sample in samples]
				results['summary'][name][variant][key] = {'median': statistics.median(values), 'variance': statistics.variance(values)}
	args.output.write_text(json.dumps(results, indent=2) + '\n')
	print(name, {variant: round(metrics['wall_seconds']['median'], 6) for variant, metrics in results['summary'][name].items()}, flush=True)

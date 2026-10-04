Worker comparisons use optimized Linux PHP ZTS and extension builds, OPcache enabled,
and JIT disabled. Each sample warms up with 1,000 tasks before measuring.

```sh
python3 benchmarks/worker.py --php /path/to/php --opcache /path/to/opcache.so \
  --extension upstream=/path/to/upstream/pmmpthread.so \
  --extension candidate=/path/to/candidate/pmmpthread.so \
  --repetitions 7 --perf --output /path/to/results.json
```

Omit `--perf` when Linux performance counters are unavailable. Results include all
samples, medians and variance for wall/CPU time, submission time, peak RSS, context
switches, and p50/p95/p99 handoff latency. Perf adds process-wide user-mode cycles/instructions,
including startup, warmup and shutdown; PHP-reported CPU/wall
and context-switch measurements cover only the measured workload.

The eight public waiters model competition from PHP code waiting on the Worker.
Task submission remains restricted to the creator. Idle mode measures 16 Workers
over one second; compare its measured CPU time rather than total process counters.
Handoff latency includes the task timestamp write and delivery to `run()`.

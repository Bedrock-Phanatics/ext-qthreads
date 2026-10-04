# Worker wakeup measurements

2026-10-04, Linux x86-64 under WSL2, PHP 8.4.15 ZTS without debug, GCC 13.3.0,
`-O2 -g`, OPcache enabled, JIT disabled, CPUs 0-3. Both comparisons use nine
alternating repetitions and the final candidate binary. Each process warms up
with 1,000 tasks. Upstream is `13673b241ceef33bf0ad6902562999a253a9023a`;
step 1 is `338a59d`. Values are medians. Wall and CPU values cover the workload;
perf user-mode cycles/instructions include startup, warmup and shutdown.

Initial final-binary comparison: upstream / step 1 / candidate.

| Workload | Wall ms | CPU ms | Voluntary switches | Peak RSS KiB |
| --- | ---: | ---: | ---: | ---: |
| throughput-20000-0 | 149.141 / 152.128 / 163.940 | 194.883 / 200.143 / 221.191 | 2950 / 2938 / 3044 | 35544 / 35616 / 35584 |
| handoff-1000-0 | 132.046 / 131.807 / 131.559 | 129.479 / 129.011 / 127.147 | 2028 / 2032 / 2030 | 14592 / 14720 / 14720 |
| handoff-1000-8 | 591.103 / 601.674 / 479.702 | 1198.389 / 1209.078 / 984.192 | 28756 / 28294 / 24049 | 17536 / 17536 / 17536 |
| throughput-20000-8 | 930.451 / 898.333 / 951.786 | 1814.380 / 1781.914 / 1818.984 | 43873 / 42831 / 44488 | 34416 / 34500 / 34468 |
| idle-16-0 | 1050.703 / 1032.867 / 1015.202 | 0.093 / 0.082 / 0.091 | 1 / 1 / 1 | 20224 / 20224 / 20224 |

Confirmation: upstream / candidate, using the same binaries.

| Workload | Wall ms | CPU ms | Voluntary switches | Peak RSS KiB |
| --- | ---: | ---: | ---: | ---: |
| throughput-20000-0 | 151.824 / 152.018 | 200.540 / 201.599 | 3056 / 3146 | 35548 / 35552 |
| handoff-1000-0 | 133.792 / 131.869 | 132.096 / 130.351 | 2031 / 2027 | 14720 / 14720 |
| handoff-1000-8 | 593.989 / 475.105 | 1208.890 / 992.992 | 28695 / 24143 | 17536 / 17664 |
| throughput-20000-8 | 964.091 / 989.411 | 1956.808 / 2004.065 | 45445 / 46447 | 34624 / 34376 |
| idle-16-0 | 1027.401 / 1011.418 | 0.092 / 0.094 | 1 / 1 | 20096 / 20224 |

| Confirmation handoff | p50 us | p95 us | p99 us |
| --- | ---: | ---: | ---: |
| handoff-1000-0 | 32.475 / 32.396 | 62.207 / 54.898 | 127.266 / 120.706 |
| handoff-1000-8 | 240.072 / 135.137 | 590.011 / 493.176 | 844.785 / 746.702 |

Contended serial handoff consistently improves: confirmation CPU is 17.9%
lower, wall time 20.0% lower and total context switches 15.4% lower than
upstream. This models competing PHP waiters, not an entire server.

Uncontended throughput was 9.9% slower in the first final-binary comparison,
with wall standard deviations of 38.3 / 26.4 / 22.9 ms. The confirmation gives
151.8 / 152.0 ms, standard deviations 15.0 / 8.3 ms, and CPU 200.5 / 201.6 ms.
Thus the earlier slowdown did not reproduce. With eight waiters, confirmation
throughput wall time is 2.6% higher (standard deviations 35.6 / 38.1 ms) and CPU
2.4% higher (standard deviations 76.8 / 69.2 ms); this falls within measured
variation, while submission throughput increases from 69,411 to 82,254 tasks/s.
Uncontended submission throughput is 148,621 / 153,384 tasks/s. Idle CPU and RSS
show no material change. The scheduler condition adds 48 bytes per Worker on
this platform, with no new per-task allocations.

Raw samples, medians, variance, cycles/instructions and submission times are in
[the initial comparison](results/worker-2026-10-04.json) and
[the confirmation](results/worker-2026-10-04-confirmation.json). WSL timing varies
between runs; these are scoped workload results, with no server-wide speed claim.

Correctness: the full PHP 8.4 ZTS PHPT suite has no failures with OPcache off/on/JIT
and protected OPcache memory, retaining the existing upstream XFAIL warning.
Full memcheck reports no failed or leaked tests. Targeted DRD, condition
initialization failure, Pool error propagation, collection, idle shutdown and
competing-waiter tests pass; the competing-waiter test also passes 50 repetitions.
A test-only naive shared-condition signal build times out in that regression.
Eight concurrent producers verify the existing creator-only submission rule.
Cross-thread getStacked() raises RuntimeException instead of dereferencing absent
queue data. Failed native construction skips user destructors; the previous
binary segfaulted in that regression. Other PHP versions and Windows retain CI
coverage and were not run locally.

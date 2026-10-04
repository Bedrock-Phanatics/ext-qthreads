Run the monitor check from the repository root on POSIX systems:

```sh
cc -std=gnu11 -Wall -Wextra -Werror -pthread -I. tests/native/monitor.c -o /tmp/pmmpthread-monitor-test
timeout 30 /tmp/pmmpthread-monitor-test
```

For the initialization-failure PHPT, build a separate test extension using
`CFLAGS="-O2 -g -include $PWD/tests/native/fault-injection.h"` when configuring it,
then run `run-tests.php` with `PMMPTHREAD_FAULT_INJECTION=1` and that extension.
The header injects one failure per `PMMPTHREAD_TEST_FAIL` setting; never use this build in production.
To check module initialization, launch PHP with each of `attr`, `type`, `mutex`, and `cond`
set as `PMMPTHREAD_TEST_FAIL`; each process must fail to load the extension cleanly.

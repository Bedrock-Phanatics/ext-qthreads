/*
  +----------------------------------------------------------------------+
  | pmmpthread                                                             |
  +----------------------------------------------------------------------+
  | Copyright (c) Joe Watkins 2015                                       |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Joe Watkins <krakjoe@php.net>                                |
  +----------------------------------------------------------------------+
 */
#ifndef HAVE_PMMPTHREAD_MONITOR_H
#define HAVE_PMMPTHREAD_MONITOR_H

typedef unsigned long pmmpthread_monitor_state_t;

typedef struct _pmmpthread_monitor_t {
	pmmpthread_monitor_state_t state;
	pthread_mutex_t          mutex;
	pthread_cond_t           cond;
} pmmpthread_monitor_t;

#define PMMPTHREAD_MONITOR_NOTHING         (0)
#define PMMPTHREAD_MONITOR_STARTED         (1<<0)
#define PMMPTHREAD_MONITOR_RUNNING         (1<<1)
#define PMMPTHREAD_MONITOR_JOINED          (1<<2)
#define PMMPTHREAD_MONITOR_ERROR           (1<<3)
#define PMMPTHREAD_MONITOR_READY           (1<<4)
#define PMMPTHREAD_MONITOR_COLLECT_GARBAGE (1<<5)
#define PMMPTHREAD_MONITOR_EXIT            (1<<6)
#define PMMPTHREAD_MONITOR_AWAIT_JOIN      (1<<7)

//the monitors this thread holds, innermost last, as a fatal error jumps over the code that would have unlocked them.
//Kept small since it lives in static TLS, deeper ones just stay locked
#define PMMPTHREAD_MONITOR_HELD_MAX 8

typedef struct _pmmpthread_monitor_held_t {
	uint32_t depth;
	pmmpthread_monitor_t* monitors[PMMPTHREAD_MONITOR_HELD_MAX];
} pmmpthread_monitor_held_t;

extern TSRM_TLS pmmpthread_monitor_held_t pmmpthread_monitor_held TSRM_TLS_MODEL_ATTR;

zend_result pmmpthread_monitor_init(pmmpthread_monitor_t* m);
void pmmpthread_monitor_destroy(pmmpthread_monitor_t* m);
/* {{{ unlocks everything this thread locked since it held depth monitors */
void pmmpthread_monitor_unwind(uint32_t depth); /* }}} */

static zend_always_inline void pmmpthread_monitor_locked(pmmpthread_monitor_t *m) {
	if (pmmpthread_monitor_held.depth < PMMPTHREAD_MONITOR_HELD_MAX) {
		pmmpthread_monitor_held.monitors[pmmpthread_monitor_held.depth] = m;
	}
	pmmpthread_monitor_held.depth++;
}

static zend_always_inline zend_bool pmmpthread_monitor_lock(pmmpthread_monitor_t *m) {
	if (pthread_mutex_lock(&m->mutex) != 0) {
		return 0;
	}
	pmmpthread_monitor_locked(m);
	return 1;
}

static zend_always_inline zend_bool pmmpthread_monitor_trylock(pmmpthread_monitor_t *m) {
	if (pthread_mutex_trylock(&m->mutex) != 0) {
		return 0;
	}
	pmmpthread_monitor_locked(m);
	return 1;
}

static zend_always_inline zend_bool pmmpthread_monitor_unlock(pmmpthread_monitor_t *m) {
	pmmpthread_monitor_held.depth--;
	ZEND_ASSERT(pmmpthread_monitor_held.depth >= PMMPTHREAD_MONITOR_HELD_MAX || pmmpthread_monitor_held.monitors[pmmpthread_monitor_held.depth] == m);
	return (pthread_mutex_unlock(&m->mutex) == 0);
}

static zend_always_inline int pmmpthread_monitor_notify(pmmpthread_monitor_t *m) {
	return pthread_cond_broadcast(&m->cond);
}

static zend_always_inline int pmmpthread_monitor_notify_one(pmmpthread_monitor_t *m) {
	return pthread_cond_signal(&m->cond);
}

pmmpthread_monitor_state_t pmmpthread_monitor_check(pmmpthread_monitor_t *m, pmmpthread_monitor_state_t state);
int pmmpthread_monitor_wait(pmmpthread_monitor_t *m, zend_long timeout);
void pmmpthread_monitor_wait_until(pmmpthread_monitor_t *m, pmmpthread_monitor_state_t state);
void pmmpthread_monitor_add(pmmpthread_monitor_t *m, pmmpthread_monitor_state_t state);
void pmmpthread_monitor_remove(pmmpthread_monitor_t *m, pmmpthread_monitor_state_t state);
#endif

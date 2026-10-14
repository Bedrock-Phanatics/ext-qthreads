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

#ifdef HAVE_CONFIG_H
#	include <config.h>
#endif

#include <src/pmmpthread.h>
#include "worker.h"
#include "queue.h"

#if PHP_VERSION_ID >= 80300
#	include <Zend/zend_hrtime.h>
#	define pmmpthread_hrtime() zend_hrtime()
#else
#	include <ext/standard/hrtime.h>
#	define pmmpthread_hrtime() php_hrtime_current()
#endif

//only a hint, the queue itself is always checked under the monitor
#ifdef HAVE_VALGRIND
#	include <valgrind/drd.h>
#	define PMMPTHREAD_HINT_INIT(p) DRD_IGNORE_VAR(*(p))
#	define PMMPTHREAD_HINT_DESTROY(p) DRD_STOP_IGNORING_VAR(*(p))
#else
#	define PMMPTHREAD_HINT_INIT(p) ((void) 0)
#	define PMMPTHREAD_HINT_DESTROY(p) ((void) 0)
#endif
#ifdef _MSC_VER
#	define PMMPTHREAD_HINT_LOAD(p) (*(volatile zend_bool*) (p))
#	define PMMPTHREAD_HINT_STORE(p, v) (*(volatile zend_bool*) (p) = (v))
#	define PMMPTHREAD_CPU_RELAX() YieldProcessor()
#else
#	include <sched.h>
#	define PMMPTHREAD_HINT_LOAD(p) __atomic_load_n((p), __ATOMIC_RELAXED)
#	define PMMPTHREAD_HINT_STORE(p, v) __atomic_store_n((p), (v), __ATOMIC_RELAXED)
#	if defined(__x86_64__) || defined(__i386__)
#		define PMMPTHREAD_CPU_RELAX() __builtin_ia32_pause()
#	elif defined(__aarch64__)
#		define PMMPTHREAD_CPU_RELAX() __asm__ __volatile__("yield")
#	else
#		define PMMPTHREAD_CPU_RELAX() ((void) 0)
#	endif
#endif

//waking a sleeping worker costs the submitter a syscall, often more than running a small task. So while tasks keep
//arriving soon after a worker runs out, it stays awake a while first. 0 disables it, see pmmpthread.worker_spin_us
uint64_t pmmpthread_worker_spin_max_ns = 0;
#define PMMPTHREAD_WORKER_SPIN_SEED_NS 20000

struct _pmmpthread_worker_data_t {
	pmmpthread_monitor_t   	*monitor;
	pmmpthread_queue queue;
	pmmpthread_queue gc;
	pmmpthread_queue_item_t *running;
	zend_ulong tasks_collected;
	pmmpthread_queue *collecting; //tasks the creator is running collectors on, see pmmpthread_worker_collect_tasks()
	zend_bool pending; //PMMPTHREAD_HINT_* only
	uint64_t idle_since;
	uint64_t spin_ns;
};

pmmpthread_worker_data_t* pmmpthread_worker_data_alloc(pmmpthread_monitor_t *monitor) {
	pmmpthread_worker_data_t *stack =
		(pmmpthread_worker_data_t*) ecalloc(1, sizeof(pmmpthread_worker_data_t));
	stack->spin_ns = MIN(PMMPTHREAD_WORKER_SPIN_SEED_NS, pmmpthread_worker_spin_max_ns);
	PMMPTHREAD_HINT_INIT(&stack->pending);

	stack->monitor = monitor;

	return stack;
}

zend_long pmmpthread_worker_task_queue_size(pmmpthread_worker_data_t *worker_data) {
	zend_long size = 0;

	if (pmmpthread_monitor_lock(worker_data->monitor)) {
		size = worker_data->queue.size;
		pmmpthread_monitor_unlock(worker_data->monitor);
	}
	return size;
}

void pmmpthread_worker_data_free(pmmpthread_worker_data_t *worker_data) {
	pmmpthread_monitor_t *monitor = worker_data->monitor;

	if (pmmpthread_monitor_lock(monitor)) {
		pmmpthread_queue_clean(&worker_data->queue);
		pmmpthread_queue_clean(&worker_data->gc);

		//we should never be freeing worker_data for a worker with active tasks
		ZEND_ASSERT(worker_data->running == NULL);

		PMMPTHREAD_HINT_DESTROY(&worker_data->pending);
		efree(worker_data);

		pmmpthread_monitor_unlock(monitor);
	}
}

zend_long pmmpthread_worker_add_task(pmmpthread_worker_data_t *worker_data, zval *value) {
	zend_long size = 0;

	if (pmmpthread_monitor_lock(worker_data->monitor)) {
		//allow submitting tasks before the worker starts, but not after it exits
		if (worker_data->monitor->state & (PMMPTHREAD_MONITOR_ERROR | PMMPTHREAD_MONITOR_AWAIT_JOIN | PMMPTHREAD_MONITOR_JOINED)) {
			pmmpthread_monitor_unlock(worker_data->monitor);
			return -1;
		}
		size = worker_data->queue.size;
		pmmpthread_queue_push_new(&worker_data->queue, value);
		PMMPTHREAD_HINT_STORE(&worker_data->pending, 1);
		if (!size) {
			pmmpthread_monitor_notify(worker_data->monitor);
		}
		size = worker_data->queue.size;
		pmmpthread_monitor_unlock(worker_data->monitor);
	} else {
		size = -1;
	}

	return size;
}

void pmmpthread_worker_add_garbage(pmmpthread_worker_data_t *worker_data, pmmpthread_queue* done_tasks_cache, zval* work_zval) {
	if (pmmpthread_monitor_lock(worker_data->monitor)) {
		pmmpthread_queue_push(&worker_data->gc, worker_data->running);
		worker_data->running = NULL;
		pmmpthread_monitor_notify(worker_data->monitor); //in case the main thread is waiting for a task
		pmmpthread_monitor_unlock(worker_data->monitor);
		pmmpthread_queue_push_new(done_tasks_cache, work_zval);
	} else {
		ZEND_ASSERT(0);
	}
}

zend_long pmmpthread_worker_dequeue_task(pmmpthread_worker_data_t *worker_data, zval *value) {
	zend_long size = 0;

	if (pmmpthread_monitor_lock(worker_data->monitor)) {
		//as counterintuitive as this looks, it is in fact expected behaviour :(
		size = pmmpthread_queue_shift(&worker_data->queue, value, PMMPTHREAD_STACK_FREE);
		pmmpthread_monitor_unlock(worker_data->monitor);
	}

	return size;
}

zend_long pmmpthread_worker_collect_tasks(pmmpthread_worker_data_t *worker_data, pmmpthread_call_t *call, pmmpthread_worker_collect_function_t collect) {
	zend_long size = 0;
	zend_long tasks_collected = 0;
	pmmpthread_queue done;

	if (!pmmpthread_monitor_lock(worker_data->monitor)) {
		return 0;
	}
	if (worker_data->collecting) {
		//called from a collector; collecting here too would mess up the order the worker frees its connections in
		size = (worker_data->queue.size + worker_data->gc.size) + (worker_data->running != NULL ? 1 : 0);
		pmmpthread_monitor_unlock(worker_data->monitor);
		return size;
	}
	//the worker would block on its monitor while we run collectors and free tasks, so do that without it
	done = worker_data->gc;
	memset(&worker_data->gc, 0, sizeof(pmmpthread_queue));
	worker_data->collecting = &done;
	pmmpthread_monitor_unlock(worker_data->monitor);

	while (done.head) {
		pmmpthread_store_full_sync_local_properties(Z_OBJ(done.head->value));
		if (!collect(call, &done.head->value)) {
			break;
		}
		pmmpthread_queue_shift(&done, NULL, PMMPTHREAD_STACK_FREE);
		tasks_collected++;
	}

	if (pmmpthread_monitor_lock(worker_data->monitor)) {
		if (done.head) {
			//uncollected tasks go back in front of the ones finished meanwhile
			done.tail->next = worker_data->gc.head;
			if (worker_data->gc.head) {
				worker_data->gc.head->prev = done.tail;
			} else {
				worker_data->gc.tail = done.tail;
			}
			worker_data->gc.head = done.head;
			worker_data->gc.size += done.size;
		}
		worker_data->collecting = NULL;
		if (tasks_collected > 0) {
			worker_data->tasks_collected += tasks_collected;
			pmmpthread_monitor_add(worker_data->monitor, PMMPTHREAD_MONITOR_COLLECT_GARBAGE);
		}

		size = (worker_data->queue.size + worker_data->gc.size) + (worker_data->running != NULL ? 1 : 0);

		pmmpthread_monitor_unlock(worker_data->monitor);
	}

	return size;
}

/* {{{ Runs a pmmpthread_store_full_sync_local_properties() on every task in the GC queue, to ensure availability of properties */
static void pmmpthread_worker_sync_tasks(pmmpthread_queue_item_t* item) {
	while (item) {
		pmmpthread_zend_object_t* threaded = PMMPTHREAD_FETCH_FROM(Z_OBJ(item->value));
		if (pmmpthread_monitor_lock(&threaded->ts_obj->monitor)) {
			pmmpthread_store_full_sync_local_properties(Z_OBJ(item->value));
			pmmpthread_monitor_unlock(&threaded->ts_obj->monitor);
		}
		item = item->next;
	}
}

zend_result pmmpthread_worker_sync_collectable_tasks(pmmpthread_worker_data_t* worker_data) {
	if (pmmpthread_monitor_lock(worker_data->monitor)) {
		pmmpthread_worker_sync_tasks(worker_data->gc.head);
		if (worker_data->collecting != NULL) {
			//only the creator collects, and it's the one calling this
			pmmpthread_worker_sync_tasks(worker_data->collecting->head);
		}

		pmmpthread_monitor_unlock(worker_data->monitor);
		return SUCCESS;
	}

	return FAILURE;
} /* }}} */

static void pmmpthread_worker_spin(pmmpthread_worker_data_t *worker_data, uint64_t deadline) {
	do {
		for (int i = 0; i < 32; i++) {
			if (PMMPTHREAD_HINT_LOAD(&worker_data->pending)) {
				return;
			}
			PMMPTHREAD_CPU_RELAX();
		}
		//don't hold up other threads when there are fewer cores than busy threads
#ifdef PHP_WIN32
		SwitchToThread();
#else
		sched_yield();
#endif
	} while (pmmpthread_hrtime() < deadline);
}

//spin about as long as tasks have recently taken to arrive, but only while that saves wakeups
static void pmmpthread_worker_adapt_spin(pmmpthread_worker_data_t *worker_data, uint64_t idle) {
	uint64_t max = pmmpthread_worker_spin_max_ns;

	if (idle <= worker_data->spin_ns) {
		//caught it while spinning; drift towards the gaps we actually see
		worker_data->spin_ns = MAX(2 * idle, worker_data->spin_ns - worker_data->spin_ns / 8);
	} else if (idle < max) {
		//slept, but spinning a bit longer would have saved the wakeup
		worker_data->spin_ns = 2 * idle;
	} else {
		//spinning wouldn't have helped
		worker_data->spin_ns /= 2;
	}
	worker_data->spin_ns = MIN(worker_data->spin_ns, max);
}

pmmpthread_monitor_state_t pmmpthread_worker_next_task(pmmpthread_worker_data_t *worker_data, pmmpthread_queue* done_tasks_cache, zval *value) {
	pmmpthread_monitor_t *monitor = worker_data->monitor;
	pmmpthread_monitor_state_t state = PMMPTHREAD_MONITOR_JOINED;

	if (pmmpthread_monitor_lock(monitor)) {
		while (1) {
			if (monitor->state & PMMPTHREAD_MONITOR_COLLECT_GARBAGE) {
				for (zend_long i = 0; i < worker_data->tasks_collected; i++) {
					pmmpthread_queue_shift(done_tasks_cache, NULL, PMMPTHREAD_STACK_FREE);
				}
				worker_data->tasks_collected = 0;
				monitor->state &= ~PMMPTHREAD_MONITOR_COLLECT_GARBAGE;
				pmmpthread_monitor_notify(monitor);
			}
			if (worker_data->queue.head) {
				if (worker_data->idle_since != 0) {
					pmmpthread_worker_adapt_spin(worker_data, pmmpthread_hrtime() - worker_data->idle_since);
					worker_data->idle_since = 0;
				}
				//this is allocated on the creator thread's ZMM, so we can't free it
				worker_data->running = worker_data->queue.head;
				pmmpthread_queue_shift(&worker_data->queue, value, PMMPTHREAD_STACK_NOTHING);
				if (worker_data->queue.head == NULL) {
					PMMPTHREAD_HINT_STORE(&worker_data->pending, 0);
				}
				state = PMMPTHREAD_MONITOR_RUNNING;
				break;
			}
			if (monitor->state & PMMPTHREAD_MONITOR_JOINED) {
				break;
			}
			if (worker_data->idle_since == 0 && pmmpthread_worker_spin_max_ns > 0) {
				worker_data->idle_since = pmmpthread_hrtime();
				if (worker_data->spin_ns > 0) {
					pmmpthread_monitor_unlock(monitor);
					pmmpthread_worker_spin(worker_data, worker_data->idle_since + worker_data->spin_ns);
					if (!pmmpthread_monitor_lock(monitor)) {
						return PMMPTHREAD_MONITOR_JOINED;
					}
					continue;
				}
			}
			pmmpthread_monitor_wait(monitor, 0);
		}
		pmmpthread_monitor_unlock(monitor);
	}

	return state;
}

zend_get_gc_buffer* pmmpthread_worker_get_gc_extra(pmmpthread_worker_data_t* worker_data) {
	zend_get_gc_buffer* buffer = zend_get_gc_buffer_create();
	if (pmmpthread_monitor_lock(worker_data->monitor)) {
		pmmpthread_queue_item_t* item = NULL;

		item = worker_data->queue.head;
		while (item != NULL) {
			zend_get_gc_buffer_add_zval(buffer, &item->value);
			item = item->next;
		}

		if (worker_data->running != NULL) {
			zend_get_gc_buffer_add_zval(buffer, &worker_data->running->value);
		}

		item = worker_data->gc.head;
		while (item != NULL) {
			zend_get_gc_buffer_add_zval(buffer, &item->value);
			item = item->next;
		}

		item = worker_data->collecting != NULL ? worker_data->collecting->head : NULL;
		while (item != NULL) {
			zend_get_gc_buffer_add_zval(buffer, &item->value);
			item = item->next;
		}

		pmmpthread_monitor_unlock(worker_data->monitor);
	}

	return buffer;
}

/* {{{ */
zend_bool pmmpthread_worker_collect_function(pmmpthread_call_t* call, zval* collectable) {
	zval result;
	zend_bool remove = 0;

	ZVAL_UNDEF(&result);

	call->fci.retval = &result;

	zend_fcall_info_argn(&call->fci, 1, collectable);

	if (zend_call_function(&call->fci, &call->fcc) != SUCCESS) {
		return remove;
	}

	zend_fcall_info_args_clear(&call->fci, 1);

	if (Z_TYPE(result) != IS_UNDEF) {
		if (zend_is_true(&result)) {
			remove = 1;
		}
		zval_ptr_dtor(&result);
	}

	return remove;
} /* }}} */

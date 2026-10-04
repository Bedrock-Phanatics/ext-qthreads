#include <assert.h>
#include <errno.h>
#include <pthread.h>
#include <sched.h>
#include <stdatomic.h>
#include <stdlib.h>
#include <sys/time.h>

#define HAVE_PMMPTHREAD_H
typedef int zend_result;
typedef unsigned char zend_bool;
#define SUCCESS 0
#define FAILURE -1

static int failure, attributes_live, mutex_live, condition_live;
static _Thread_local int lock_calls;
static atomic_int waits;
static atomic_bool done;

static int test_attr_init(pthread_mutexattr_t *attr) {
	assert(!attributes_live);
	if (failure == 1) return EAGAIN;
	int result = pthread_mutexattr_init(attr);
	attributes_live = result == 0;
	return result;
}

static int test_attr_settype(pthread_mutexattr_t *attr, int type) {
	assert(attributes_live);
	return failure == 2 ? EINVAL : pthread_mutexattr_settype(attr, type);
}

static int test_attr_destroy(pthread_mutexattr_t *attr) {
	assert(attributes_live);
	attributes_live = 0;
	return pthread_mutexattr_destroy(attr);
}

static int test_mutex_init(pthread_mutex_t *mutex, const pthread_mutexattr_t *attr) {
	assert(attributes_live && !mutex_live);
	if (failure == 3) return EAGAIN;
	int result = pthread_mutex_init(mutex, attr);
	mutex_live = result == 0;
	return result;
}

static int test_mutex_destroy(pthread_mutex_t *mutex) {
	assert(mutex_live);
	mutex_live = 0;
	return pthread_mutex_destroy(mutex);
}

static int test_cond_init(pthread_cond_t *cond, const pthread_condattr_t *attr) {
	assert(mutex_live && !condition_live);
	if (failure == 4) return EAGAIN;
	int result = pthread_cond_init(cond, attr);
	condition_live = result == 0;
	return result;
}

static int test_cond_destroy(pthread_cond_t *cond) {
	assert(condition_live);
	condition_live = 0;
	return pthread_cond_destroy(cond);
}

static int test_lock(pthread_mutex_t *mutex) {
	lock_calls++;
	return pthread_mutex_lock(mutex);
}

static int test_wait(pthread_cond_t *cond, pthread_mutex_t *mutex) {
	atomic_fetch_add(&waits, 1);
	return failure == 5 ? EINVAL : pthread_cond_wait(cond, mutex);
}

#define pthread_mutexattr_init test_attr_init
#define pthread_mutexattr_settype test_attr_settype
#define pthread_mutexattr_destroy test_attr_destroy
#define pthread_mutex_init test_mutex_init
#define pthread_mutex_destroy test_mutex_destroy
#define pthread_cond_init test_cond_init
#define pthread_cond_destroy test_cond_destroy
#define pthread_mutex_lock test_lock
#define pthread_cond_wait test_wait
#define free(pointer) assert(!"monitor must not free caller-owned memory")
#include "../../src/monitor.c"

static void *waiter(void *monitor) {
	pmmpthread_monitor_wait_until(monitor, PMMPTHREAD_MONITOR_RUNNING);
	atomic_store(&done, 1);
	return NULL;
}

int main(void) {
	pmmpthread_monitor_t monitor;
	for (failure = 1; failure <= 4; failure++) {
		assert(pmmpthread_monitor_init(&monitor) == FAILURE);
		assert(!attributes_live && !mutex_live && !condition_live);
	}
	failure = 0;
	for (int i = 0; i < 500; i++) {
		assert(pmmpthread_monitor_init(&monitor) == SUCCESS);
		assert(monitor.state == PMMPTHREAD_MONITOR_NOTHING);
		pmmpthread_monitor_add(&monitor, PMMPTHREAD_MONITOR_READY);
		lock_calls = 0;
		pmmpthread_monitor_wait_until(&monitor, PMMPTHREAD_MONITOR_READY);
		assert(lock_calls == 1);
		pmmpthread_monitor_remove(&monitor, PMMPTHREAD_MONITOR_READY);
		assert(!pmmpthread_monitor_check(&monitor, PMMPTHREAD_MONITOR_READY));
		pmmpthread_monitor_destroy(&monitor);
		assert(!attributes_live && !mutex_live && !condition_live);
	}

	assert(pmmpthread_monitor_init(&monitor) == SUCCESS);
	pthread_t thread;
	assert(pthread_create(&thread, NULL, waiter, &monitor) == 0);
	while (atomic_load(&waits) < 1) sched_yield();
	assert(pmmpthread_monitor_lock(&monitor));
	assert(pmmpthread_monitor_notify(&monitor) == 0);
	assert(pmmpthread_monitor_unlock(&monitor));
	while (atomic_load(&waits) < 2) sched_yield();
	assert(!atomic_load(&done));
	pmmpthread_monitor_add(&monitor, PMMPTHREAD_MONITOR_RUNNING);
	assert(pthread_join(thread, NULL) == 0);
	assert(atomic_load(&done));
	pmmpthread_monitor_remove(&monitor, PMMPTHREAD_MONITOR_RUNNING);
	assert(pmmpthread_monitor_lock(&monitor));
	assert(pmmpthread_monitor_wait(&monitor, 1000) == ETIMEDOUT);
	assert(pmmpthread_monitor_notify_one(&monitor) == 0);
	assert(pmmpthread_monitor_unlock(&monitor));
	failure = 5;
	pmmpthread_monitor_wait_until(&monitor, PMMPTHREAD_MONITOR_RUNNING);
	pmmpthread_monitor_destroy(&monitor);
	return 0;
}

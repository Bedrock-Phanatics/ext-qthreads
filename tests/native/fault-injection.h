#include <errno.h>
#include <pthread.h>
#include <stdlib.h>
#include <string.h>

static inline int pmmpthread_test_fail(const char *operation) {
	const char *failure = getenv("PMMPTHREAD_TEST_FAIL");
	if (failure == NULL || strcmp(failure, operation) != 0) return 0;
	unsetenv("PMMPTHREAD_TEST_FAIL");
	return 1;
}

static inline void *pmmpthread_test_calloc(size_t count, size_t size) {
	return pmmpthread_test_fail("calloc") ? NULL : calloc(count, size);
}

static inline int pmmpthread_test_attr_init(pthread_mutexattr_t *attr) {
	return pmmpthread_test_fail("attr") ? EAGAIN : pthread_mutexattr_init(attr);
}

static inline int pmmpthread_test_attr_settype(pthread_mutexattr_t *attr, int type) {
	return pmmpthread_test_fail("type") ? EINVAL : pthread_mutexattr_settype(attr, type);
}

static inline int pmmpthread_test_mutex_init(pthread_mutex_t *mutex, const pthread_mutexattr_t *attr) {
	return pmmpthread_test_fail("mutex") ? EAGAIN : pthread_mutex_init(mutex, attr);
}

static inline int pmmpthread_test_cond_init(pthread_cond_t *cond, const pthread_condattr_t *attr) {
	return pmmpthread_test_fail("cond") ? EAGAIN : pthread_cond_init(cond, attr);
}

#define calloc pmmpthread_test_calloc
#define pthread_mutexattr_init pmmpthread_test_attr_init
#define pthread_mutexattr_settype pmmpthread_test_attr_settype
#define pthread_mutex_init pmmpthread_test_mutex_init
#define pthread_cond_init pmmpthread_test_cond_init

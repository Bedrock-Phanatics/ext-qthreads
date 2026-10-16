/*
  +----------------------------------------------------------------------+
  | pmmpthread                                                             |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
 */

#include <src/pmmpthread.h>

#define ThreadSafeQueue_method(name) PHP_METHOD(pmmp_thread_ThreadSafeQueue, name)

#define PMMPTHREAD_FETCH_QUEUE ((pmmpthread_queue_object_t*) PMMPTHREAD_FETCH_TS)

/* {{{ proto void ThreadSafeQueue::push(mixed $value) */
ThreadSafeQueue_method(push)
{
	pmmpthread_queue_object_t *queue = PMMPTHREAD_FETCH_QUEUE;
	zval *value;
	zend_result result;

	ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_THROW, 1, 1)
		Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();

	if (Z_TYPE_P(value) == IS_NULL) {
		zend_argument_value_error(1, "cannot be null");
		RETURN_THROWS();
	}

	pthread_mutex_lock(&queue->mutex);
	if (queue->closed) {
		pthread_mutex_unlock(&queue->mutex);
		zend_throw_exception_ex(spl_ce_RuntimeException, 0, "Cannot push to a closed %s", ZSTR_VAL(Z_OBJCE_P(getThis())->name));
		RETURN_THROWS();
	}
	queue->pushing++;
	pthread_mutex_unlock(&queue->mutex);

	result = pmmpthread_store_queue_push(Z_OBJ_P(getThis()), value);

	pthread_mutex_lock(&queue->mutex);
	queue->pushing--;
	if (result == SUCCESS) {
		queue->available++;
	}
	if (queue->closed && queue->pushing == 0) {
		//consumers may be waiting for this push before giving up on a closed queue
		pthread_cond_broadcast(&queue->cond);
	} else if (result == SUCCESS) {
		pthread_cond_signal(&queue->cond);
	}
	pthread_mutex_unlock(&queue->mutex);

	if (result == FAILURE && !EG(exception)) {
		zend_throw_error(
			pmmpthread_ce_nts_value_error,
			"Cannot push non-thread-safe value of type %s to %s",
			zend_zval_type_name(value),
			ZSTR_VAL(Z_OBJCE_P(getThis())->name)
		);
	}
} /* }}} */

/* {{{ proto mixed ThreadSafeQueue::pop([?int $timeout = null]) */
ThreadSafeQueue_method(pop)
{
	pmmpthread_queue_object_t *queue = PMMPTHREAD_FETCH_QUEUE;
	zend_long timeout = -1;
	bool timeout_null = 1;
	zend_bool reserved;
	struct timespec deadline;

	ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_THROW, 0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG_OR_NULL(timeout, timeout_null)
	ZEND_PARSE_PARAMETERS_END();

	if (timeout_null) {
		timeout = -1;
	} else if (timeout < 0) {
		zend_argument_value_error(1, "must be greater than or equal to 0");
		RETURN_THROWS();
	} else if (timeout > 0) {
		struct timeval now;
		gettimeofday(&now, NULL);
		zend_long usec = now.tv_usec + (timeout % 1000000);
		deadline.tv_sec = now.tv_sec + (timeout / 1000000) + (usec / 1000000);
		deadline.tv_nsec = (usec % 1000000) * 1000;
	}

	pthread_mutex_lock(&queue->mutex);
	while (queue->available == 0 && !(queue->closed && queue->pushing == 0) && timeout != 0) {
		if (timeout < 0) {
			pthread_cond_wait(&queue->cond, &queue->mutex);
		} else if (pthread_cond_timedwait(&queue->cond, &queue->mutex, &deadline) != 0) {
			//not compared with ETIMEDOUT: pthreads4w and PHP's Windows headers may disagree on its value
			break;
		}
	}
	reserved = queue->available > 0;
	if (reserved) {
		queue->available--;
	}
	pthread_mutex_unlock(&queue->mutex);

	if (!reserved) {
		RETURN_NULL();
	}
	pmmpthread_store_queue_shift(Z_OBJ_P(getThis()), return_value);
} /* }}} */

/* {{{ proto void ThreadSafeQueue::close() */
ThreadSafeQueue_method(close)
{
	pmmpthread_queue_object_t *queue = PMMPTHREAD_FETCH_QUEUE;

	zend_parse_parameters_none_throw();

	pthread_mutex_lock(&queue->mutex);
	queue->closed = 1;
	pthread_cond_broadcast(&queue->cond);
	pthread_mutex_unlock(&queue->mutex);
} /* }}} */

/* {{{ proto bool ThreadSafeQueue::isClosed() */
ThreadSafeQueue_method(isClosed)
{
	pmmpthread_queue_object_t *queue = PMMPTHREAD_FETCH_QUEUE;
	zend_bool closed;

	zend_parse_parameters_none_throw();

	pthread_mutex_lock(&queue->mutex);
	closed = queue->closed;
	pthread_mutex_unlock(&queue->mutex);

	RETURN_BOOL(closed);
} /* }}} */

/* {{{ */
int pmmpthread_queue_count(zend_object *object, zend_long *count) {
	pmmpthread_queue_object_t *queue = (pmmpthread_queue_object_t*) PMMPTHREAD_FETCH_TS_FROM(object);

	pthread_mutex_lock(&queue->mutex);
	*count = queue->available;
	pthread_mutex_unlock(&queue->mutex);

	return SUCCESS;
} /* }}} */

/* {{{ proto int ThreadSafeQueue::count() */
ThreadSafeQueue_method(count)
{
	zend_long count;

	zend_parse_parameters_none_throw();

	pmmpthread_queue_count(Z_OBJ_P(getThis()), &count);
	RETURN_LONG(count);
} /* }}} */

/* {{{ */
zend_object_iterator* pmmpthread_queue_iterator_create(zend_class_entry *ce, zval *object, int by_ref) {
	zend_throw_error(NULL, "%s cannot be iterated, use pop() instead", ZSTR_VAL(ce->name));
	return NULL;
} /* }}} */

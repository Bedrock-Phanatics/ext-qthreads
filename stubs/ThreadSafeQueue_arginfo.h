/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 0000000000000000000000000000000000000000 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_pmmp_thread_ThreadSafeQueue_push, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_pmmp_thread_ThreadSafeQueue_pop, 0, 0, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_pmmp_thread_ThreadSafeQueue_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_pmmp_thread_ThreadSafeQueue_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_pmmp_thread_ThreadSafeQueue_count, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()


ZEND_METHOD(pmmp_thread_ThreadSafeQueue, push);
ZEND_METHOD(pmmp_thread_ThreadSafeQueue, pop);
ZEND_METHOD(pmmp_thread_ThreadSafeQueue, close);
ZEND_METHOD(pmmp_thread_ThreadSafeQueue, isClosed);
ZEND_METHOD(pmmp_thread_ThreadSafeQueue, count);


static const zend_function_entry class_pmmp_thread_ThreadSafeQueue_methods[] = {
	ZEND_ME(pmmp_thread_ThreadSafeQueue, push, arginfo_class_pmmp_thread_ThreadSafeQueue_push, ZEND_ACC_PUBLIC)
	ZEND_ME(pmmp_thread_ThreadSafeQueue, pop, arginfo_class_pmmp_thread_ThreadSafeQueue_pop, ZEND_ACC_PUBLIC)
	ZEND_ME(pmmp_thread_ThreadSafeQueue, close, arginfo_class_pmmp_thread_ThreadSafeQueue_close, ZEND_ACC_PUBLIC)
	ZEND_ME(pmmp_thread_ThreadSafeQueue, isClosed, arginfo_class_pmmp_thread_ThreadSafeQueue_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(pmmp_thread_ThreadSafeQueue, count, arginfo_class_pmmp_thread_ThreadSafeQueue_count, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_pmmp_thread_ThreadSafeQueue(zend_class_entry *class_entry_pmmp_thread_ThreadSafe, zend_class_entry *class_entry_Countable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "pmmp\\thread", "ThreadSafeQueue", class_pmmp_thread_ThreadSafeQueue_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_pmmp_thread_ThreadSafe);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES;
	zend_class_implements(class_entry, 1, class_entry_Countable);

	return class_entry;
}

<?php

/**
 * @generate-class-entries
 */

namespace pmmp\thread;

/**
 * A FIFO queue for passing values between threads.
 *
 * Values are copied using the same rules as ThreadSafe properties (arrays are converted to ThreadSafeArray).
 * Blocking pop() doesn't use the monitor used by synchronized()/wait()/notify(), so it may be called anywhere.
 */
final class ThreadSafeQueue extends ThreadSafe implements \Countable{

    /**
     * Adds a value to the end of the queue and wakes one waiting consumer.
     *
     * @throws \RuntimeException if the queue has been closed
     * @throws NonThreadSafeValueError if the value can't be shared between threads
     */
    public function push(mixed $value) : void{}

    /**
     * Removes and returns the value at the front of the queue.
     *
     * @param int|null $timeout microseconds to wait for a value; null waits indefinitely, 0 doesn't wait
     *
     * @return mixed the value, or null if none arrived in time or the queue is closed and empty
     */
    public function pop(?int $timeout = null) : mixed{}

    /**
     * Prevents further pushes and wakes all waiting consumers. Values already queued can still be popped.
     */
    public function close() : void{}

    public function isClosed() : bool{}

    /**
     * Returns the number of values which can currently be popped.
     */
    public function count() : int{}
}

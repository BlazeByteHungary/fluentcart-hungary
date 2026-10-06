<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

/**
 * MySQL named-lock helper (05_DATA_SCHEMA §10.2, §8.2). GET_LOCK serializes migrations and other
 * short critical sections; it is released in a finally block by callers.
 */
final class Lock
{
    public function acquire(string $name, int $timeout = 10): bool
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout));
        return $result === '1' || $result === 1;
    }

    public function release(string $name): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}

<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

/**
 * A job handler. Implementations register a hook name and resolve it only from non-secret args.
 * A handler must be idempotent through the ledger state machine.
 */
interface JobInterface
{
    public function hook(): string;

    /** @param array<string,mixed> $args */
    public function handle(array $args): void;
}

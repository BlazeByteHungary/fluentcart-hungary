<?php
declare(strict_types=1);

namespace FluentCartHungary\Core;

/**
 * Module contract (ADR-001). A module registers its own hooks only; it must never hard-reference another
 * module's classes. A thrown exception during register_hooks() degrades only that module.
 */
interface ModuleInterface
{
    public function id(): string;

    public function label(): string;

    /** Soft requirements, e.g. ['core']. Missing requirements block only this module. */
    public function requires(): array;

    /** Whether the module is enabled when no explicit setting exists. */
    public function enabledByDefault(): bool;

    /** Register hooks/filters/jobs. May throw; the registry isolates it. */
    public function registerHooks(): void;

    /** @return array{ok:bool,message:string} cheap, non-mutating capability probe. */
    public function probe(): array;
}

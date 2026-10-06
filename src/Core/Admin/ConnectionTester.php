<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

/**
 * Connection-tester contract (W0 core self-test; provider modules implement this in later waves).
 * A test is non-mutating and makes no provider HTTP call in W0.
 */
interface ConnectionTester
{
    public function id(): string;

    public function label(): string;

    /**
     * @return array{ok:bool,label:string,checks:list<array{id:string,ok:bool,message:string}>}
     */
    public function run(): array;
}

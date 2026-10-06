<?php

use FluentCartHungary\Core\Ledger\LedgerRepository;

$repo = new LedgerRepository();
echo wp_json_encode([
    'ledger_total' => array_sum($repo->countByState()),
    'jobs_total' => array_sum((new \FluentCartHungary\Core\Jobs\JobStore())->countsByState()),
]);

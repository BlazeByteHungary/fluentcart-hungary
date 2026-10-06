<?php

use FluentCartHungary\Core\Ledger\LedgerRepository;

$key = (string) getenv('FCH_LEDGER_KEY');
if ($key === '') {
    echo wp_json_encode(['error' => 'missing FCH_LEDGER_KEY']);
    return;
}

$repo = new LedgerRepository();
$result = $repo->claim([
    'idempotency_key' => $key,
    'order_id' => 990002,
    'module' => 'payments',
    'provider' => 'barion',
    'kind' => 'payment_start',
    'mode' => 'test',
    'account_id' => 'acct-parallel',
    'currency' => 'HUF',
    'amount_minor' => 5000,
]);

echo wp_json_encode(['pid' => getmypid(), 'created' => $result['created'], 'id' => (int) $result['row']->id]);

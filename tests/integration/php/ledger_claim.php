<?php

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;

$repo = new LedgerRepository();
$key = ExternalOperation::idempotencyKey(990001, 'create_invoice', 0);
$attrs = [
    'idempotency_key' => $key,
    'order_id' => 990001,
    'module' => 'billing',
    'provider' => 'szamlazz',
    'kind' => 'create_invoice',
    'mode' => 'test',
    'account_id' => 'acct-1',
    'currency' => 'HUF',
    'amount_minor' => 12345,
];

$first = $repo->claim($attrs);
$second = $repo->claim($attrs);
$count = (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM `' . $repo->table() . '` WHERE idempotency_key = %s', $key));

echo wp_json_encode([
    'key' => $key,
    'first_created' => $first['created'],
    'second_created' => $second['created'],
    'same_id' => (int) $first['row']->id === (int) $second['row']->id,
    'count' => $count,
]);

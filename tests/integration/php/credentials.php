<?php

use FluentCartHungary\Core\Settings\CredentialStore;

$store = new CredentialStore();
$secret = 'SECRET-abc-1234';
$stored = $store->set('szamlazz', 'test', 'acct-x', 'szamlaagentkulcs', $secret, true);
$roundTrip = $store->get('szamlazz', 'test', 'acct-x', 'szamlaagentkulcs');

$masked = $store->getMasked();
$maskedJson = wp_json_encode($masked);

$all = CredentialStore::all();
$recordKey = CredentialStore::recordKey('szamlazz', 'test', 'acct-x', 'szamlaagentkulcs');
$cipher = (string) ($all[$recordKey]['data'] ?? '');

echo wp_json_encode([
    'stored' => $stored,
    'roundtrip' => $roundTrip === $secret,
    'masked_clean' => !str_contains($maskedJson, $secret),
    'at_rest_opaque' => $cipher !== '' && !str_contains($cipher, $secret),
    'masked_preview' => $masked[0]['preview'] ?? '',
]);

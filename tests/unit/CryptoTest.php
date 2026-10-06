<?php
declare(strict_types=1);

use FluentCartHungary\Core\Settings\CredentialStore;

fch_test('ADR-006 AEAD encrypt/decrypt round-trip is opaque', function (): void {
    $key = CredentialStore::deriveKey('unit-test-key-material');
    assert_same(32, strlen($key));
    $plain = 'szuper-titkos-kulcs-1234';
    $cipher = CredentialStore::encryptValue($plain, $key);
    assert_true(!str_contains($cipher, $plain), 'ciphertext must not contain plaintext');
    assert_same($plain, CredentialStore::decryptValue($cipher, $key));
});

fch_test('ADR-006 wrong key fails to decrypt', function (): void {
    $key = CredentialStore::deriveKey('key-one');
    $wrong = CredentialStore::deriveKey('key-two');
    $cipher = CredentialStore::encryptValue('secret-value', $key);
    assert_same(null, CredentialStore::decryptValue($cipher, $wrong));
});

fch_test('ADR-006 nonce makes ciphertexts unique', function (): void {
    $key = CredentialStore::deriveKey('same-key');
    assert_true(CredentialStore::encryptValue('x', $key) !== CredentialStore::encryptValue('x', $key));
});

fch_test('ADR-006 mask reveals at most the last 4 characters', function (): void {
    $secret = 'abcdefgh1234';
    $masked = CredentialStore::mask($secret);
    assert_true(!str_contains($masked, $secret), 'masked must not contain the secret');
    assert_true(str_ends_with($masked, '1234'), 'masked may show last 4');
    assert_true(!str_contains($masked, 'abcdefgh'));
});

fch_test('ADR-006 short secrets are fully masked', function (): void {
    $masked = CredentialStore::mask('abc');
    assert_true(!str_contains($masked, 'abc'));
    assert_same(4, strlen($masked));
});

fch_test('redaction helper removes secret field names', function (): void {
    if (!function_exists('fch_redact_payload')) {
        require dirname(__DIR__, 2) . '/src/Core/functions.php';
    }
    $redacted = fch_redact_payload([
        'user' => 'merchant',
        'api_key' => 'super-secret',
        'nested' => ['PosKey' => 'possecret', 'clientId' => 'public-id'],
    ]);
    assert_same('merchant', $redacted['user']);
    assert_same('***REDACTED***', $redacted['api_key']);
    assert_same('***REDACTED***', $redacted['nested']['PosKey']);
    assert_same('public-id', $redacted['nested']['clientId']);
});

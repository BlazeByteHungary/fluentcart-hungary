<?php
declare(strict_types=1);

use FluentCartHungary\Core\Storage\ArtifactCrypto;

/**
 * Artifact encryption at rest (W1 access-control closure): sealed stored bytes must be useless on their
 * own, legacy plaintext must stay readable, and a wrong key must never decrypt.
 */

fch_test('UT-AC-01 seal/open round-trip with a derived key', function (): void {
    $secret = 'unit-test-artifact-secret';
    $plain = "%PDF-1.4\nfake invoice bytes\n%%EOF\n";
    $sealed = ArtifactCrypto::seal($plain, $secret);
    assert_true(is_string($sealed) && $sealed !== '', 'seal returns a string');
    assert_true($sealed !== $plain, 'sealed bytes differ from plaintext');
    assert_true(ArtifactCrypto::isSealed($sealed), 'magic detected');
    assert_same($plain, ArtifactCrypto::open($sealed, $secret));
});

fch_test('UT-AC-02 legacy plaintext passes through unchanged', function (): void {
    $plain = "%PDF-1.4 legacy\n%%EOF\n";
    assert_true(!ArtifactCrypto::isSealed($plain));
    assert_same($plain, ArtifactCrypto::open($plain, 'any-secret'));
});

fch_test('UT-AC-03 a different key cannot open the payload (authenticated)', function (): void {
    $sealed = ArtifactCrypto::seal('secret document', 'key-one');
    assert_true($sealed !== null);
    assert_true(ArtifactCrypto::open((string) $sealed, 'key-two') === null, 'wrong key must fail closed');
});

fch_test('UT-AC-04 tampered ciphertext is rejected', function (): void {
    $sealed = (string) ArtifactCrypto::seal('secret document', 'key-one');
    $sealed[strlen($sealed) - 1] = $sealed[strlen($sealed) - 1] === 'A' ? 'B' : 'A';
    assert_true(ArtifactCrypto::open($sealed, 'key-one') === null, 'tampering must fail closed');
});

fch_test('UT-AC-05 every seal uses a fresh nonce (no deterministic ciphertext)', function (): void {
    $a = ArtifactCrypto::seal('same input', 'key-one');
    $b = ArtifactCrypto::seal('same input', 'key-one');
    assert_true($a !== null && $b !== null && $a !== $b, 'nonces must differ');
});

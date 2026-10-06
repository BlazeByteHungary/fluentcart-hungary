<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

/**
 * SimplePay v2 message authentication and framing (04_OTP_SIMPLE_CONTRACT.md §2, §3; SimplePay_2.x_Payment
 * doc §3.1-§3.2, SDK `SimplePayV21.php`).
 *
 * Contract facts encoded here:
 *  - `signature = base64(HMAC_SHA384(body_json_string, trim(SECRET_KEY)))`, sent in the `Signature` header
 *    on every request AND response AND inbound IPN (SDK L1115-1120).
 *  - The signature base is the EXACT body byte string. For outbound calls we sign the string we send;
 *    for responses/IPN we verify the RAW body — never a re-serialized JSON (doc §3.2 "Signature alapja
 *    az üzenet body ... a teljes JSON string").
 *  - Browser back: GET `r` (base64 JSON) + `s` (signature over the base64-DECODED JSON string as-is)
 *    (doc §3.12; SDK `isBackSignatureCheck`).
 *  - Every message carries a 32-char random `salt`; the SDK sends `sdkVersion` in `start`.
 *  - IPN confirmation: echo the received fields plus `receiveDate` (ISO 8601), signed the same way
 *    (doc §3.14; SDK `SimplePayIpn::isIpnSignatureCheck`).
 *
 * Pure PHP + WordPress JSON helper only: unit-testable without WordPress.
 */
final class SimplepaySignature
{
    public const ALGO = 'sha384';
    public const SDK_NAME = 'FluentCartHungary_SimplePay';

    /** base64(HMAC-SHA384(body, trim(secret))) — SDK `getSignature()`. */
    public static function sign(string $body, string $secret): string
    {
        return base64_encode(hash_hmac(self::ALGO, $body, trim($secret), true));
    }

    /** Constant-time verification; false on empty secret (never signs/accepts with an empty key). */
    public static function verify(string $body, string $signature, string $secret): bool
    {
        $signature = trim($signature);
        if ($signature === '' || trim($secret) === '') {
            return false;
        }
        return hash_equals(self::sign($body, $secret), $signature);
    }

    /** 32-char random salt (doc §3.1: 32 random characters). */
    public static function salt(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** `sdkVersion` in the documented `name_version:md5(file)` shape (doc §3.1; SDK prepare()). */
    public static function sdkVersion(): string
    {
        $version = defined('FCH_VERSION') ? (string) FCH_VERSION : '0';
        return self::SDK_NAME . '_' . $version . ':' . md5_file(__FILE__);
    }

    /**
     * JSON string for the request body. PHP's default JSON flags (escaped slashes/unicode) match the
     * official SDK's `json_encode($transactionBase)`; the exact returned bytes are what gets signed.
     *
     * @param array<string,mixed> $payload
     */
    public static function encode(array $payload): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        return is_string($json) ? $json : '{}';
    }

    /**
     * Decode the browser-back `r` parameter: base64 -> JSON string AS-IS (the signature base is the decoded
     * string, not a re-encode — doc §3.12, SDK `isBackSignatureCheck`).
     *
     * @return string|null the decoded JSON string, or null when malformed
     */
    public static function decodeBack(string $r): ?string
    {
        // Query-string '+' can arrive as a space (form decoding); base64 never contains spaces.
        $r = str_replace(' ', '+', trim($r));
        $decoded = base64_decode($r, true);
        if ($decoded === false) {
            // URL-decoding edge: pad to a valid base64 length before giving up.
            $padded = $r . str_repeat('=', (4 - (strlen($r) % 4)) % 4);
            $decoded = base64_decode($padded, true);
        }
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }
        json_decode($decoded);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $decoded;
    }

    /**
     * Validate the browser-back pair. Signature base = decoded JSON string (doc §3.12).
     *
     * @return string|null the decoded JSON string when the signature is valid, null otherwise
     */
    public static function verifyBack(string $r, string $s, string $secret): ?string
    {
        $json = self::decodeBack($r);
        if ($json === null) {
            return null;
        }
        return self::verify($json, $s, $secret) ? $json : null;
    }

    /** IPN/browser confirmation timestamp (doc §3.14 receiveDate; SDK `date("c")`). */
    public static function receiveDate(): string
    {
        return date('c');
    }
}

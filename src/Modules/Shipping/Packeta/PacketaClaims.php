<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;

/**
 * Packeta returns via API using the CURRENT method `createPacketClaimWithPassword()` (04_PACKETA_CONTRACT
 * §3 item 5, §4 row 7; the legacy `createPacketClaim()` is deprecated 2026-05-14 and is NOT used — PKT-006).
 *
 * The claim `number` is the original packet barcode prefixed with `RET` (§4 row 7). The response returns
 * `{id, barcode, barcodeText, password}`; the 9-digit password is the customer-facing artifact and must be
 * stored atomically (§7). There is NO idempotency key and no lookup-by-number for claims, so the intent is
 * persisted before the call and the password is stored in the sealed private carrier store; a lost
 * response is reconciled by `packetInfo`/`packetStatus` afterwards (never by re-POSTing the claim).
 *
 * NOTE (constraints §2 / matrix PKT-007): the external-operation kind vocabulary has no `claim_create`
 * kind and ExternalOperation.php is not owned by this module, so the claim is NOT routed through the
 * OperationRunner ledger; instead its intent and artifacts are persisted on the order meta + private
 * store. This is documented as a W2-level limitation, not a silent deviation.
 */
final class PacketaClaims
{
    /**
     * Build the `claimWithPasswordAttributes` payload (04 §4 row 7).
     *
     * @param array<string,mixed> $snapshot number,email,phone,value_minor,currency,eshop,consign_country,
     *                                      send_email_to_customer
     * @return array<string,mixed>
     */
    public static function buildAttributes(array $snapshot): array
    {
        $currency = strtoupper((string) ($snapshot['currency'] ?? 'HUF'));
        $attributes = [
            'number' => (string) ($snapshot['number'] ?? ''),
        ];
        $email = trim((string) ($snapshot['email'] ?? ''));
        if ($email !== '') {
            $attributes['email'] = $email;
        }
        $phone = trim((string) ($snapshot['phone'] ?? ''));
        if ($phone !== '') {
            $attributes['phone'] = $phone;
        }
        $attributes['value'] = self::amount((int) ($snapshot['value_minor'] ?? 0), $currency);
        $attributes['currency'] = $currency;

        $eshop = trim((string) ($snapshot['eshop'] ?? ''));
        if ($eshop !== '') {
            $attributes['eshop'] = $eshop;
        }
        $attributes['consignCountry'] = strtoupper((string) ($snapshot['consign_country'] ?? 'HU'));
        $attributes['sendEmailToCustomer'] = !empty($snapshot['send_email_to_customer']);

        return $attributes;
    }

    /** @param array<string,mixed> $snapshot @return list<string> */
    public static function validate(array $snapshot): array
    {
        $errors = [];
        $number = trim((string) ($snapshot['number'] ?? ''));
        if ($number === '') {
            $errors[] = 'missing_number';
        } elseif (!str_starts_with($number, 'RET')) {
            // 04 §4 row 7: the claim number is the original barcode with a RET prefix.
            $errors[] = 'claim_number_must_start_with_ret';
        }
        if (trim((string) ($snapshot['email'] ?? '')) === '' && trim((string) ($snapshot['phone'] ?? '')) === '') {
            $errors[] = 'missing_contact';
        }
        if ((int) ($snapshot['value_minor'] ?? 0) <= 0) {
            $errors[] = 'value_required';
        }
        if (strlen(trim((string) ($snapshot['consign_country'] ?? ''))) !== 2) {
            $errors[] = 'invalid_consign_country';
        }
        return array_values($errors);
    }

    /** 04 §4 row 7: `number` = original barcode with a `RET` prefix. */
    public static function claimNumber(string $originalBarcode): string
    {
        $barcode = PacketaClient::canonicalPacketId($originalBarcode);
        if ($barcode === '') {
            return '';
        }
        return str_starts_with($barcode, 'RET') ? $barcode : 'RET' . $barcode;
    }

    /**
     * Create the claim through the provider and store the returned password atomically.
     *
     * @param array<string,mixed> $attrs    claimWithPasswordAttributes
     * @param array<string,mixed> $snapshot used only to resolve the persisted refs
     * @return array{ok:bool,status:string,id:?string,barcode:?string,barcode_text:?string,password_ref:?string,error:?string}
     */
    public function create(array $attrs, array $snapshot = [], ?PacketaClient $client = null): array
    {
        $client ??= new PacketaClient();
        if (!$client->hasCredentials()) {
            return self::failure('missing_credentials');
        }

        $response = $client->createPacketClaimWithPassword($attrs);
        if ($response->error !== null || $response->status === 0) {
            return self::failure('timeout');
        }
        if (!$response->isSuccess()) {
            return self::failure('http_' . $response->status);
        }

        $decoded = PacketaClient::decode($response->body);
        if (!$decoded['ok']) {
            return self::failure((string) ($decoded['fault'] ?? 'claim_fault'));
        }

        $id = PacketaClient::firstString($decoded['result'], 'id');
        $barcode = PacketaClient::firstString($decoded['result'], 'barcode');
        $barcodeText = PacketaClient::firstString($decoded['result'], 'barcodeText');
        $password = PacketaClient::firstString($decoded['result'], 'password');

        $passwordRef = null;
        if ($password !== '') {
            try {
                $stored = (new CarrierLabelStore(PacketaModule::CARRIER))->store('packeta-claim-' . ($barcode !== '' ? $barcode : $id), $password, 'txt');
                $passwordRef = $stored['ref'];
            } catch (\Throwable $e) {
                // The password must never be logged or returned in an error; a storage failure is explicit.
                return self::failure('password_storage_failed');
            }
        }

        return [
            'ok' => true,
            'status' => 'created',
            'id' => $id !== '' ? $id : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'barcode_text' => $barcodeText !== '' ? $barcodeText : null,
            'password_ref' => $passwordRef,
            'error' => null,
        ];
    }

    /**
     * Order-level return creation: resolve the original packet barcode from the confirmed shipment,
     * persist the claim intent before the call, then create and store the password ref + claim ref.
     *
     * @return array{ok:bool,status:string,reason?:string,id:?string,barcode:?string,password_ref:?string}
     */
    public function createForOrder(int $orderId): array
    {
        $order = PacketaModule::loadOrder($orderId);
        if ($order === null) {
            return ['ok' => false, 'status' => 'error', 'reason' => 'order_not_found', 'id' => null, 'barcode' => null, 'password_ref' => null];
        }
        if (!PacketaModule::hasCredentials()) {
            return ['ok' => false, 'status' => 'error', 'reason' => 'missing_credentials', 'id' => null, 'barcode' => null, 'password_ref' => null];
        }

        $barcode = $this->resolveOriginalBarcode($orderId, $order);
        if ($barcode === '') {
            return ['ok' => false, 'status' => 'error', 'reason' => 'no_confirmed_packet', 'id' => null, 'barcode' => null, 'password_ref' => null];
        }

        $snapshot = $this->buildSnapshot($order, $barcode);
        $errors = self::validate($snapshot);
        if ($errors !== []) {
            return ['ok' => false, 'status' => 'validation_error', 'reason' => 'claim_validation_failed', 'id' => null, 'barcode' => null, 'password_ref' => null];
        }

        // Persist intent (non-secret) before dispatch; never a re-POST based on this alone (no idempotency).
        if (method_exists($order, 'updateMeta')) {
            $order->updateMeta('_fch_packeta_claim_number', (string) $snapshot['number']);
        }

        $result = $this->create(self::buildAttributes($snapshot), $snapshot);
        if ($result['ok'] && method_exists($order, 'updateMeta')) {
            $order->updateMeta('_fch_packeta_claim_ref', (string) ($result['barcode'] ?? ''));
            $order->updateMeta('_fch_packeta_claim_password_ref', (string) ($result['password_ref'] ?? ''));
        }

        return [
            'ok' => (bool) $result['ok'],
            'status' => (string) $result['status'],
            'reason' => $result['error'] ?? null,
            'id' => $result['id'],
            'barcode' => $result['barcode'],
            'password_ref' => $result['password_ref'],
        ];
    }

    /**
     * Public snapshot builder for the ledger-routed claim flow (W4/PKT-007): same documented shape as the
     * legacy order-level path, so both routes create identical provider payloads.
     *
     * @return array<string,mixed>
     */
    public function buildSnapshotFor(object $order, string $barcode): array
    {
        return $this->buildSnapshot($order, $barcode);
    }

    /** @return array<string,mixed> */
    private function buildSnapshot(object $order, string $barcode): array
    {
        $address = $order->shipping_address ?? null;
        if (!is_object($address)) {
            $address = $order->billing_address ?? null;
        }
        $currency = strtoupper((string) ($order->currency ?? 'HUF'));
        $country = is_object($address) ? strtoupper(trim((string) ($address->country ?? 'HU'))) : 'HU';
        if (strlen($country) !== 2) {
            $country = 'HU';
        }

        $phone = is_object($address) ? trim((string) ($address->phone ?? '')) : '';
        $billing = $order->billing_address ?? null;
        if ($phone === '' && is_object($billing)) {
            $phone = trim((string) ($billing->phone ?? ''));
        }

        $valueMinor = FluentCartMoney::fromCartCents((int) ($order->total_amount ?? 0), $currency);

        return [
            'number' => self::claimNumber($barcode),
            'email' => is_object($address) ? (string) ($address->email ?? '') : '',
            'phone' => $phone,
            'value_minor' => max(0, $valueMinor),
            'currency' => $currency,
            'eshop' => (string) PacketaModule::setting('eshop', ''),
            'consign_country' => $country,
            'send_email_to_customer' => (bool) PacketaModule::setting('claim_send_email', false),
        ];
    }

    private function resolveOriginalBarcode(int $orderId, object $order): string
    {
        $repo = new ShipmentRepository();
        foreach (array_reverse($repo->listForOrder($orderId)) as $row) {
            if ((string) ($row->carrier ?? '') !== PacketaModule::CARRIER) {
                continue;
            }
            if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                continue;
            }
            $barcode = trim((string) ($row->barcode ?? ''));
            if ($barcode === '') {
                $barcode = trim((string) ($row->tracking_number ?? ''));
            }
            if ($barcode !== '') {
                return PacketaClient::canonicalPacketId($barcode);
            }
        }
        return '';
    }

    private static function amount(int $minor, string $currency): string
    {
        try {
            return \FluentCartHungary\Core\Money\Money::fromMinor($minor, $currency);
        } catch (\Throwable $e) {
            return (string) $minor;
        }
    }

    /** @return array{ok:bool,status:string,id:?string,barcode:?string,barcode_text:?string,password_ref:?string,error:?string} */
    private static function failure(string $error): array
    {
        return [
            'ok' => false,
            'status' => 'error',
            'id' => null,
            'barcode' => null,
            'barcode_text' => null,
            'password_ref' => null,
            'error' => $error,
        ];
    }
}

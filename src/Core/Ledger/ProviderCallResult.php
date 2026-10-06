<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

/**
 * Outcome of one provider call, produced by a provider client and applied by OperationRunner
 * (05_DATA_SCHEMA §7.1/§7.2). Payload arrays must be secret-free; the runner redacts again before
 * persisting (04 CRED §3).
 */
final class ProviderCallResult
{
    public const SUCCESS = 'success';
    public const REJECTED = 'rejected';
    public const UNCERTAIN = 'uncertain';
    public const LOCAL_ERROR = 'local_error';

    /**
     * @param array<string,mixed> $fields ledger/mirror fields (external_id, external_ref2, …)
     * @param array<string,mixed> $requestPayload  secret-free request summary
     * @param array<string,mixed> $responsePayload secret-free response summary
     */
    private function __construct(
        public readonly string $status,
        public readonly array $fields = [],
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly array $requestPayload = [],
        public readonly array $responsePayload = [],
    ) {
    }

    public static function success(array $fields = [], array $request = [], array $response = []): self
    {
        return new self(self::SUCCESS, $fields, null, null, $request, $response);
    }

    public static function rejected(string $code, string $message = '', array $request = [], array $response = []): self
    {
        return new self(self::REJECTED, [], $code, $message, $request, $response);
    }

    /** Timeout / lost response after dispatch: outcome unknown, never auto-retried (invariant §1.4). */
    public static function uncertain(string $reason, array $request = [], array $response = []): self
    {
        return new self(self::UNCERTAIN, [], $reason, null, $request, $response);
    }

    /** Definitive local failure before/without dispatch (validation, auth config, DNS). */
    public static function localError(string $code, string $message = '', array $request = []): self
    {
        return new self(self::LOCAL_ERROR, [], $code, $message, $request);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isUncertain(): bool
    {
        return $this->status === self::UNCERTAIN;
    }
}

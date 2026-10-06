<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Http;

/**
 * Immutable HTTP outcome. Bodies are never logged by the client; callers must redact before persisting.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly ?string $error = null,
        public readonly int $durationMs = 0,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function isTimeout(): bool
    {
        return $this->error !== null;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }
        return null;
    }
}

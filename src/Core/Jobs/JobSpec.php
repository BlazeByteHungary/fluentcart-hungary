<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

/**
 * Immutable job request. Args must be non-secret (credentials are resolved at run time).
 */
final class JobSpec
{
    public function __construct(
        public readonly string $jobKey,
        public readonly string $hook,
        public readonly array $args = [],
        public readonly int $priority = 10,
        public readonly int $maxAttempts = 5,
        public readonly ?string $scheduledBy = null,
    ) {
        if ($this->jobKey === '' || strlen($this->jobKey) > 191) {
            throw new \InvalidArgumentException('job_key must be 1..191 chars.');
        }
        if ($this->hook === '' || strlen($this->hook) > 191) {
            throw new \InvalidArgumentException('hook must be 1..191 chars.');
        }
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1.');
        }
    }

    public function argsJson(): string
    {
        return (string) wp_json_encode($this->args);
    }

    /** @param object|array $row */
    public static function fromRow($row): self
    {
        $row = (array) $row;
        $args = [];
        if (!empty($row['args_json'])) {
            $decoded = json_decode((string) $row['args_json'], true);
            if (is_array($decoded)) {
                $args = $decoded;
            }
        }
        return new self(
            (string) $row['job_key'],
            (string) $row['hook'],
            $args,
            (int) ($row['priority'] ?? 10),
            (int) ($row['max_attempts'] ?? 5),
            isset($row['scheduled_by']) ? (string) $row['scheduled_by'] : null,
        );
    }
}

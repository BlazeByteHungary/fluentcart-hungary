<?php
declare(strict_types=1);

namespace FluentCartHungary\Tests\Mock;

/**
 * Programmable MCP/provider transport mock for tests (05_ACCEPTANCE_AND_TEST_PLAN mock harness).
 * It hooks `fch/http/pre_request` so no network is ever touched; rules match by URL substring, in order.
 * Response arrays use the shape BaseClient already understands:
 *   ['status'=>int,'headers'=>array,'body'=>string,'error'=>?string]
 */
final class MockTransport
{
    /** @var list<array{pattern:string,response:array|callable,times:int,used:int}> */
    private array $rules = [];

    /** @var list<array{url:string,method:string,at:float}> */
    private array $calls = [];

    private bool $installed = false;

    public static function make(): self
    {
        return new self();
    }

    /** @param array<string,mixed>|callable $response */
    public function on(string $urlPattern, array|callable $response, int $times = 0): self
    {
        $this->rules[] = ['pattern' => $urlPattern, 'response' => $response, 'times' => $times, 'used' => 0];
        return $this;
    }

    /** Register the mock on the WordPress filter. Chainable. */
    public function install(): self
    {
        if (!$this->installed && function_exists('add_filter')) {
            add_filter('fch/http/pre_request', [$this, 'filter'], 10, 3);
            $this->installed = true;
        }
        return $this;
    }

    public function uninstall(): void
    {
        if ($this->installed && function_exists('remove_filter')) {
            remove_filter('fch/http/pre_request', [$this, 'filter'], 10);
        }
        $this->installed = false;
    }

    /**
     * @param mixed $injected
     * @param mixed $url
     * @param mixed $args
     * @return mixed
     */
    public function filter($injected, $url, $args = [])
    {
        $match = $this->handle((string) $url, is_array($args) ? $args : []);
        return $match ?? $injected;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function handle(string $url, array $args = []): ?array
    {
        $body = $args['body'] ?? null;
        if (is_array($body)) {
            $body = wp_json_encode($body) ?: '';
        }
        $body = is_string($body) ? $body : '';
        $this->calls[] = [
            'url' => $url,
            'method' => (string) ($args['method'] ?? 'GET'),
            'at' => microtime(true),
            // Request body capture lets money-boundary and duplication assertions inspect the real
            // payload built for the provider (bounded to keep the in-memory log small).
            'body' => strlen($body) > 20000 ? substr($body, 0, 20000) : $body,
        ];

        foreach ($this->rules as $index => $rule) {
            if ($rule['times'] > 0 && $rule['used'] >= $rule['times']) {
                continue;
            }
            if (!str_contains($url, $rule['pattern'])) {
                continue;
            }
            $this->rules[$index]['used']++;
            $response = $rule['response'];
            if (is_callable($response)) {
                $response = $response($url, $args);
            }
            return is_array($response) ? $response : self::response(200, (string) $response);
        }

        return null;
    }

    /** @return list<array{url:string,method:string,at:float}> */
    public function calls(string $urlFragment = ''): array
    {
        if ($urlFragment === '') {
            return $this->calls;
        }
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_contains($call['url'], $urlFragment)));
    }

    public function callCount(string $urlFragment = ''): int
    {
        return count($this->calls($urlFragment));
    }

    /** @return array<string,mixed>|null the most recent matching call (url/method/at/body) */
    public function lastCall(string $urlFragment = ''): ?array
    {
        $calls = $this->calls($urlFragment);
        if ($calls === []) {
            return null;
        }
        return $calls[count($calls) - 1];
    }

    /** @return array<string,mixed> */
    public static function response(int $status, array|string $body = [], array $headers = [], ?string $error = null): array
    {
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => is_array($body) ? (string) json_encode($body) : $body,
            'error' => $error,
        ];
    }

    /** @param array<string,mixed> $body */
    public static function json(int $status, array $body, array $headers = []): array
    {
        return self::response($status, $body, $headers + ['Content-Type' => 'application/json']);
    }

    /** Simulate a transport timeout/lost response (BaseClient => Response with error). */
    public static function timeout(): array
    {
        return self::response(0, '', [], 'http_request_failed');
    }

    /** @return array<string,mixed> */
    public static function fixture(string $relative): array
    {
        $path = dirname(__DIR__) . '/mock/fixtures/' . ltrim($relative, '/');
        if (!is_file($path)) {
            throw new \RuntimeException('Fixture not found: ' . $relative);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture is not valid JSON: ' . $relative);
        }
        return $decoded;
    }

    public static function fixtureRaw(string $relative): string
    {
        $path = dirname(__DIR__) . '/mock/fixtures/' . ltrim($relative, '/');
        if (!is_file($path)) {
            throw new \RuntimeException('Fixture not found: ' . $relative);
        }
        return (string) file_get_contents($path);
    }
}

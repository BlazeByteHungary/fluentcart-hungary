<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Http;

/**
 * Thin shared transport base (05_ARCHITECTURE §3.2). TLS verification is always on; timeouts are always
 * explicit; nothing is logged. Provider clients extend this in later waves. A `fch/http/pre_request`
 * filter lets the test harness inject mock responses without network.
 */
class BaseClient
{
    protected string $baseUrl = '';

    protected int $connectTimeout = 5;

    protected int $timeout = 20;

    /** @var array<string,string> */
    protected array $headers = [];

    public function __construct(string $baseUrl = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function get(string $path, array $query = []): Response
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = [], array $headers = []): Response
    {
        return $this->request('POST', $this->baseUrl . $path, [
            'body' => $body,
            'headers' => $headers,
        ]);
    }

    /**
     * @param array{body?:mixed,headers?:array<string,string>,query?:array<string,mixed>} $args
     */
    public function request(string $method, string $url, array $args = []): Response
    {
        $requestArgs = [
            'method' => strtoupper($method),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'sslverify' => true,
            'redirection' => 0,
            'headers' => array_merge($this->headers, $args['headers'] ?? []),
        ];
        if (array_key_exists('body', $args)) {
            $requestArgs['body'] = $args['body'];
        }

        $requestArgs = apply_filters('fch/http/args', $requestArgs, $url, $method);

        $injected = apply_filters('fch/http/pre_request', null, $url, $requestArgs);
        if ($injected instanceof Response) {
            return $injected;
        }
        if (is_array($injected)) {
            return new Response(
                (int) ($injected['status'] ?? 0),
                (array) ($injected['headers'] ?? []),
                (string) ($injected['body'] ?? ''),
                isset($injected['error']) ? (string) $injected['error'] : null
            );
        }

        if (!function_exists('wp_remote_request')) {
            return new Response(0, [], '', 'wp_http_unavailable');
        }

        $started = microtime(true);
        $raw = wp_remote_request($url, $requestArgs);
        $duration = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($raw)) {
            return new Response(0, [], '', $raw->get_error_code(), $duration);
        }

        $headersRaw = wp_remote_retrieve_headers($raw);
        if (is_object($headersRaw) && method_exists($headersRaw, 'getAll')) {
            // WordPress >= 6.2 returns a CaseInsensitiveDictionary; casting it with (array) hides the
            // data behind mangled property keys, which silently broke header-based signature checks.
            $headers = (array) $headersRaw->getAll();
        } else {
            $headers = is_array($headersRaw) ? $headersRaw : (array) $headersRaw;
        }

        return new Response(
            (int) wp_remote_retrieve_response_code($raw),
            $headers,
            (string) wp_remote_retrieve_body($raw),
            null,
            $duration
        );
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function setTimeouts(int $connect, int $total): void
    {
        $this->connectTimeout = max(1, $connect);
        $this->timeout = max(1, $total);
    }
}

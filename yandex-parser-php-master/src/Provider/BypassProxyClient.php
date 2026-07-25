<?php

declare(strict_types=1);

namespace YandexParser\Provider;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;

/**
 * Talks to a locally running "bypass proxy" service (a separate project: a
 * Python/FastAPI server that fetches URLs through a rotating Xray/SOCKS5 fleet
 * with TLS fingerprinting to get past IP/geo/anti-bot blocks - see
 * proxy_server.py's `POST /fetch` and `GET /health`).
 *
 * This client is only ever consulted as a fallback after a direct request
 * fails or is blocked (see DirectYandexMapsProvider) - it is never on the
 * hot path, and if the service isn't configured or can't be reached, every
 * method here fails soft (returns false/null) instead of throwing, so normal
 * direct-only scraping keeps working without it.
 */
final class BypassProxyClient
{
    private ClientInterface $http;

    private ?bool $started = null;

    public function __construct(
        string $baseUrl = 'http://127.0.0.1:8765',
        ?ClientInterface $http = null,
        private readonly ?string $startDir = null,
        private readonly string $pythonExecutable = 'python',
        private readonly float $healthTimeoutSeconds = 1.5,
        private readonly float $startupTimeoutSeconds = 20.0,
    ) {
        $this->http = $http ?? new HttpClient([
            'base_uri' => rtrim($baseUrl, '/').'/',
        ]);
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->http->request('GET', 'health', [
                'connect_timeout' => $this->healthTimeoutSeconds,
                'timeout' => $this->healthTimeoutSeconds,
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Checks (once per instance) whether the bypass proxy is reachable and,
     * if not and a project directory was configured, tries to spawn it in the
     * background and waits for it to come up. The result is cached for the
     * lifetime of this instance - callers hit failed/blocked requests one at a
     * time, and re-running this whole probe-and-wait sequence on every single
     * one of them would make a widespread block far slower, not faster.
     */
    public function ensureStarted(): bool
    {
        if ($this->started !== null) {
            return $this->started;
        }

        return $this->started = $this->probeAndStart();
    }

    private function probeAndStart(): bool
    {
        if ($this->isHealthy()) {
            return true;
        }

        if ($this->startDir === null || ! is_dir($this->startDir)) {
            return false;
        }

        $this->spawnServer();

        $deadline = microtime(true) + $this->startupTimeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($this->isHealthy()) {
                return true;
            }

            usleep(300_000);
        }

        return false;
    }

    /**
     * Best-effort, fire-and-forget launch of `python proxy_server.py` as a
     * detached background process so it outlives this PHP process. Never
     * throws: if spawning fails, ensureStarted() simply keeps polling /health
     * until its timeout and returns false.
     */
    private function spawnServer(): void
    {
        $script = 'proxy_server.py';

        if ($this->startDir === null || ! is_file($this->startDir.DIRECTORY_SEPARATOR.$script)) {
            return;
        }

        $handle = null;

        try {
            if (stripos(PHP_OS, 'WIN') === 0) {
                $command = sprintf(
                    'cd /d %s && start "" /min %s %s',
                    escapeshellarg($this->startDir),
                    escapeshellarg($this->pythonExecutable),
                    escapeshellarg($script),
                );
            } else {
                $command = sprintf(
                    'cd %s && nohup %s %s > proxy_server.log 2>&1 &',
                    escapeshellarg($this->startDir),
                    escapeshellarg($this->pythonExecutable),
                    escapeshellarg($script),
                );
            }

            $handle = popen($command, 'r');
        } catch (Throwable) {
            $handle = null;
        } finally {
            if (is_resource($handle)) {
                pclose($handle);
            }
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, mixed>, body: string}
     *
     * @throws RuntimeException
     * @throws GuzzleException
     */
    public function fetch(string $url, array $headers = [], int $timeoutSeconds = 30): array
    {
        $response = $this->http->request('POST', 'fetch', [
            'json' => [
                'url' => $url,
                'headers' => $headers,
                'timeout' => $timeoutSeconds,
            ],
            'timeout' => $timeoutSeconds + 5,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded) || ! isset($decoded['body']) || ! isset($decoded['status'])) {
            throw new RuntimeException('Bypass proxy returned an unexpected response');
        }

        $body = is_string($decoded['body']) ? base64_decode($decoded['body'], true) : false;
        $headersOut = is_array($decoded['headers'] ?? null) ? $decoded['headers'] : [];

        return [
            'status' => (int) $decoded['status'],
            'headers' => $headersOut,
            'body' => $body !== false ? $body : '',
        ];
    }
}

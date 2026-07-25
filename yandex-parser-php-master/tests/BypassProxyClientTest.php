<?php

declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use YandexParser\Provider\BypassProxyClient;

function makeBypassProxyClient(array $responses, array &$history, array $extraArgs = []): BypassProxyClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $http = new HttpClient([
        'base_uri' => 'http://127.0.0.1:8765/',
        'handler' => $stack,
    ]);

    return new BypassProxyClient(http: $http, ...$extraArgs);
}

it('reports healthy when /health responds with 200', function () {
    $history = [];
    $client = makeBypassProxyClient([new Response(200, [], '{"ok":true}')], $history);

    expect($client->isHealthy())->toBeTrue()
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe('http://127.0.0.1:8765/health');
});

it('reports unhealthy when the request fails', function () {
    $history = [];
    $client = makeBypassProxyClient([new Response(500, [], 'error')], $history);

    expect($client->isHealthy())->toBeFalse();
});

it('ensureStarted returns true without spawning anything when already healthy', function () {
    $history = [];
    $client = makeBypassProxyClient([new Response(200, [], '{"ok":true}')], $history);

    expect($client->ensureStarted())->toBeTrue()
        ->and($history)->toHaveCount(1);
});

it('ensureStarted returns false without attempting to spawn when no start dir is configured', function () {
    $history = [];
    $client = makeBypassProxyClient([new Response(500, [], 'error')], $history);

    expect($client->ensureStarted())->toBeFalse();
});

it('ensureStarted memoizes the result and does not re-probe on subsequent calls', function () {
    $history = [];
    $client = makeBypassProxyClient([new Response(200, [], '{"ok":true}')], $history);

    expect($client->ensureStarted())->toBeTrue()
        ->and($client->ensureStarted())->toBeTrue()
        ->and($client->ensureStarted())->toBeTrue()
        ->and($history)->toHaveCount(1);
});

it('fetches and decodes a base64 body from the proxy /fetch endpoint', function () {
    $history = [];
    $payload = json_encode([
        'status' => 200,
        'headers' => ['content-type' => 'text/html'],
        'body' => base64_encode('<html>ok</html>'),
        'method' => 'tls',
        'proxy_used' => 'socks5://127.0.0.1:10808',
    ], JSON_THROW_ON_ERROR);
    $client = makeBypassProxyClient([new Response(200, [], $payload)], $history);

    $result = $client->fetch('https://yandex.ru/maps/org/example/123/');

    expect($result['status'])->toBe(200)
        ->and($result['body'])->toBe('<html>ok</html>')
        ->and($result['headers'])->toBe(['content-type' => 'text/html'])
        ->and((string) $history[0]['request']->getUri())->toBe('http://127.0.0.1:8765/fetch')
        ->and($history[0]['request']->getMethod())->toBe('POST');

    $sentBody = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($sentBody['url'])->toBe('https://yandex.ru/maps/org/example/123/');
});

it('throws when the proxy response is missing required fields', function () {
    $history = [];
    $client = makeBypassProxyClient([new Response(200, [], '{"unexpected":true}')], $history);

    expect(fn () => $client->fetch('https://example.ru'))
        ->toThrow(RuntimeException::class, 'unexpected response');
});

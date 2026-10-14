<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Vernesoft\Gate;

/**
 * A Gate client on a mocked transport that records every request.
 */
abstract class GateTestCase extends TestCase
{
    protected const KEY = 'vrn_9f8a7c1b2d3e4f50';

    /** @var array<int, array{request: RequestInterface}> */
    protected array $history = [];

    /**
     * @param  Response[]  $responses
     */
    protected function gate(array $responses, string $baseUrl = 'https://api.vernesoft.com'): Gate
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Gate(apiKey: self::KEY, baseUrl: $baseUrl, httpClient: new Client(['handler' => $stack]));
    }

    protected static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    protected function request(int $i = 0): RequestInterface
    {
        return $this->history[$i]['request'];
    }

    /**
     * Asserts method, path and (decoded) query of a recorded request.
     *
     * @param  array<string, string>  $query
     */
    protected function assertRequest(string $method, string $path, array $query = [], int $i = 0): void
    {
        $request = $this->request($i);
        $this->assertSame($method, $request->getMethod());
        $this->assertSame($path, $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $actual);
        $this->assertSame($query, $actual);
    }

    protected function body(int $i = 0): mixed
    {
        return json_decode((string) $this->request($i)->getBody(), true);
    }

    protected function rawBody(int $i = 0): string
    {
        return (string) $this->request($i)->getBody();
    }
}

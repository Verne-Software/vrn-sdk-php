<?php

declare(strict_types=1);

namespace Vernesoft;

use Vernesoft\Core\Errors\VerneException;

class Verne
{
    private ?Relay $relayClient = null;

    private ?Gate $gateClient = null;

    public function __construct(
        private ?string $relay = null,
        private ?string $gate = null,
        private string $baseUrl = 'https://api.vernesoft.com',
        private int $timeoutSeconds = 30,
    ) {}

    public function relay(): Relay
    {
        if ($this->relay === null) {
            throw new VerneException('Relay API key not provided.');
        }

        return $this->relayClient ??= new Relay(
            apiKey: $this->relay,
            baseUrl: $this->baseUrl,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function gate(): Gate
    {
        if ($this->gate === null) {
            throw new VerneException('Gate API key not provided.');
        }

        return $this->gateClient ??= new Gate(
            apiKey: $this->gate,
            baseUrl: $this->baseUrl,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }
}

<?php

declare(strict_types=1);

namespace Vernesoft;

use Vernesoft\Core\Errors\VerneException;
use Vernesoft\Core\HttpClient;

/**
 * The unified client.
 *
 * A Nautilus API key looks like `vrn_…` and carries a set of scopes — the APIs it may
 * call (`relay`, `gate`, `clockwork`, `passepartout`). Pass it once as `apiKey:`. The
 * per-service arguments are for using a *different* key for one service; they override
 * `apiKey` for that service. Keys made before scopes existed (`vrn_gate_…`,
 * `vrn_relay_…`, …) keep working for the one API they name. There is no test or live
 * mode: every key acts on your real data.
 *
 * The SDK does not check scopes: a call to an API the key has no scope for is refused by
 * the API with `403 forbidden`.
 *
 *     $verne = new Verne(apiKey: getenv('VERNE_API_KEY'));
 *     $verne->gate()->organizations()->create(name: 'Acme');
 */
class Verne
{
    private ?Relay $relayClient = null;

    private ?Gate $gateClient = null;

    private ?Passepartout $passepartoutClient = null;

    private ?Clockwork $clockworkClient = null;

    /**
     * @param  string|null  $relay  key for Relay, when it differs from `$apiKey`
     * @param  string|null  $gate  key for Gate, when it differs from `$apiKey`
     * @param  string|null  $passepartout  key for Passepartout, when it differs from `$apiKey`
     * @param  string|null  $clockwork  key for Clockwork, when it differs from `$apiKey`
     * @param  string|null  $apiKey  the key used for every service that has no key of its own.
     *                               Last so positional 1.3.0 calls keep working; pass it by name.
     */
    public function __construct(
        private ?string $relay = null,
        private ?string $gate = null,
        private ?string $passepartout = null,
        private ?string $clockwork = null,
        private string $baseUrl = HttpClient::DEFAULT_BASE_URL,
        private int $timeoutSeconds = 30,
        private ?string $apiKey = null,
    ) {}

    public function relay(): Relay
    {
        return $this->relayClient ??= new Relay(
            apiKey: $this->keyFor('Relay', 'relay', $this->relay),
            baseUrl: $this->baseUrl,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function gate(): Gate
    {
        return $this->gateClient ??= new Gate(
            apiKey: $this->keyFor('Gate', 'gate', $this->gate),
            baseUrl: $this->baseUrl,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function passepartout(): Passepartout
    {
        return $this->passepartoutClient ??= new Passepartout(
            apiKey: $this->keyFor('Passepartout', 'passepartout', $this->passepartout),
            baseUrl: $this->baseUrl,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function clockwork(): Clockwork
    {
        return $this->clockworkClient ??= new Clockwork(
            apiKey: $this->keyFor('Clockwork', 'clockwork', $this->clockwork),
            baseUrl: $this->baseUrl,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    private function keyFor(string $label, string $argument, ?string $serviceKey): string
    {
        $key = $serviceKey ?? $this->apiKey;

        if ($key === null || $key === '') {
            throw new VerneException(
                $label.' API key not provided. Pass `apiKey:` (or `'.$argument.':`) to the Verne constructor.',
            );
        }

        return $key;
    }
}

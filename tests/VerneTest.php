<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use PHPUnit\Framework\TestCase;
use Vernesoft\Clockwork;
use Vernesoft\Core\Errors\VerneException;
use Vernesoft\Gate;
use Vernesoft\Relay;
use Vernesoft\Verne;

class VerneTest extends TestCase
{
    public function test_relay_throws_when_key_not_provided(): void
    {
        $verne = new Verne(gate: 'vrn_9f8a7c1b2d3e4f50');

        $this->expectException(VerneException::class);
        $this->expectExceptionMessage('Relay API key not provided.');

        $verne->relay();
    }

    public function test_gate_throws_when_key_not_provided(): void
    {
        $verne = new Verne(relay: 'vrn_9f8a7c1b2d3e4f50');

        $this->expectException(VerneException::class);
        $this->expectExceptionMessage('Gate API key not provided.');

        $verne->gate();
    }

    public function test_relay_returns_relay_instance(): void
    {
        $verne = new Verne(relay: 'vrn_9f8a7c1b2d3e4f50');

        $this->assertInstanceOf(Relay::class, $verne->relay());
    }

    public function test_gate_returns_gate_instance(): void
    {
        $verne = new Verne(gate: 'vrn_9f8a7c1b2d3e4f50');

        $this->assertInstanceOf(Gate::class, $verne->gate());
    }

    public function test_relay_is_lazily_initialized(): void
    {
        $verne = new Verne(relay: 'vrn_9f8a7c1b2d3e4f50');

        $relay1 = $verne->relay();
        $relay2 = $verne->relay();

        $this->assertSame($relay1, $relay2);
    }

    public function test_gate_is_lazily_initialized(): void
    {
        $verne = new Verne(gate: 'vrn_9f8a7c1b2d3e4f50');

        $gate1 = $verne->gate();
        $gate2 = $verne->gate();

        $this->assertSame($gate1, $gate2);
    }

    public function test_both_services_can_be_used_together(): void
    {
        $verne = new Verne(
            relay: 'vrn_9f8a7c1b2d3e4f50',
            gate: 'vrn_9f8a7c1b2d3e4f50',
        );

        $this->assertInstanceOf(Relay::class, $verne->relay());
        $this->assertInstanceOf(Gate::class, $verne->gate());
    }

    public function test_clockwork_throws_when_key_not_provided(): void
    {
        $verne = new Verne(relay: 'vrn_9f8a7c1b2d3e4f50');

        $this->expectException(VerneException::class);
        $this->expectExceptionMessage('Clockwork API key not provided.');

        $verne->clockwork();
    }

    public function test_clockwork_returns_clockwork_instance(): void
    {
        $verne = new Verne(clockwork: 'vrn_9f8a7c1b2d3e4f50');

        $this->assertInstanceOf(Clockwork::class, $verne->clockwork());
    }

    public function test_clockwork_is_lazily_initialized(): void
    {
        $verne = new Verne(clockwork: 'vrn_9f8a7c1b2d3e4f50');

        $clockwork1 = $verne->clockwork();
        $clockwork2 = $verne->clockwork();

        $this->assertSame($clockwork1, $clockwork2);
    }

    public function test_api_key_is_used_for_every_service(): void
    {
        $verne = new Verne(apiKey: 'vrn_shared');

        foreach (['relay', 'gate', 'passepartout', 'clockwork'] as $service) {
            $this->assertSame('vrn_shared', $this->keyOf($verne->{$service}()), $service);
        }
    }

    public function test_a_per_service_key_overrides_api_key_for_that_service_only(): void
    {
        $verne = new Verne(clockwork: 'vrn_other', apiKey: 'vrn_shared');

        $this->assertSame('vrn_other', $this->keyOf($verne->clockwork()));
        $this->assertSame('vrn_shared', $this->keyOf($verne->gate()));
    }

    public function test_positional_1_3_arguments_still_work(): void
    {
        $verne = new Verne('vrn_relay_key', 'vrn_gate_key');

        $this->assertSame('vrn_relay_key', $this->keyOf($verne->relay()));
        $this->assertSame('vrn_gate_key', $this->keyOf($verne->gate()));
    }

    public function test_without_any_key_the_error_names_api_key(): void
    {
        $verne = new Verne;

        $this->expectException(VerneException::class);
        $this->expectExceptionMessage('Gate API key not provided. Pass `apiKey:`');

        $verne->gate();
    }

    /** The key a service client sends, read from its transport. */
    private function keyOf(object $service): string
    {
        $http = (new \ReflectionProperty($service, 'httpClient'))->getValue($service);

        return (new \ReflectionProperty($http, 'apiKey'))->getValue($http);
    }
}

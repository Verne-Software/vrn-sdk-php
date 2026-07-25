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
        $verne = new Verne(gate: 'vrn_gate_test_sk_abc');

        $this->expectException(VerneException::class);
        $this->expectExceptionMessage('Relay API key not provided.');

        $verne->relay();
    }

    public function test_gate_throws_when_key_not_provided(): void
    {
        $verne = new Verne(relay: 'vrn_relay_test_sk_abc');

        $this->expectException(VerneException::class);
        $this->expectExceptionMessage('Gate API key not provided.');

        $verne->gate();
    }

    public function test_relay_returns_relay_instance(): void
    {
        $verne = new Verne(relay: 'vrn_relay_test_sk_abc');

        $this->assertInstanceOf(Relay::class, $verne->relay());
    }

    public function test_gate_returns_gate_instance(): void
    {
        $verne = new Verne(gate: 'vrn_gate_test_sk_abc');

        $this->assertInstanceOf(Gate::class, $verne->gate());
    }

    public function test_relay_is_lazily_initialized(): void
    {
        $verne = new Verne(relay: 'vrn_relay_test_sk_abc');

        $relay1 = $verne->relay();
        $relay2 = $verne->relay();

        $this->assertSame($relay1, $relay2);
    }

    public function test_gate_is_lazily_initialized(): void
    {
        $verne = new Verne(gate: 'vrn_gate_test_sk_abc');

        $gate1 = $verne->gate();
        $gate2 = $verne->gate();

        $this->assertSame($gate1, $gate2);
    }

    public function test_both_services_can_be_used_together(): void
    {
        $verne = new Verne(
            relay: 'vrn_relay_test_sk_abc',
            gate: 'vrn_gate_test_sk_abc',
        );

        $this->assertInstanceOf(Relay::class, $verne->relay());
        $this->assertInstanceOf(Gate::class, $verne->gate());
    }

    public function test_clockwork_throws_when_key_not_provided(): void
    {
        $verne = new Verne(relay: 'vrn_relay_test_sk_abc');

        $this->expectException(VerneException::class);
        $this->expectExceptionMessage('Clockwork API key not provided.');

        $verne->clockwork();
    }

    public function test_clockwork_returns_clockwork_instance(): void
    {
        $verne = new Verne(clockwork: 'vrn_clockwork_test_sk_abc');

        $this->assertInstanceOf(Clockwork::class, $verne->clockwork());
    }

    public function test_clockwork_is_lazily_initialized(): void
    {
        $verne = new Verne(clockwork: 'vrn_clockwork_test_sk_abc');

        $clockwork1 = $verne->clockwork();
        $clockwork2 = $verne->clockwork();

        $this->assertSame($clockwork1, $clockwork2);
    }
}

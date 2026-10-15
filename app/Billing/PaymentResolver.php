<?php

namespace App\Billing;

use App\Billing\Gateways\FakePaymentGateway;
use App\Billing\Gateways\RazorpayGateway;
use App\Billing\Gateways\StripeGateway;
use App\Models\Tenant;
use InvalidArgumentException;

class PaymentResolver
{
    private ?PaymentGateway $fakeGateway = null;

    public function __construct(
        private readonly array $config,
    ) {}

    public function swapFake(PaymentGateway $fake): void
    {
        $this->fakeGateway = $fake;
    }

    public function resolveForTenant(Tenant $tenant, ?string $currency = null): PaymentGateway
    {
        if ($this->fakeGateway !== null) {
            return $this->fakeGateway;
        }

        $driver = $this->config['driver'] ?? 'auto';

        if ($driver === 'fake') {
            return $this->fakeGateway = new FakePaymentGateway;
        }

        if ($driver !== 'auto') {
            return $this->resolve($driver);
        }

        $normalizedCurrency = strtolower($currency ?? $this->config['default_currency'] ?? 'usd');

        // Check currency routing
        $currencyDriver = $this->config['routing']['currencies'][$normalizedCurrency] ?? null;
        if ($currencyDriver) {
            return $this->resolve($currencyDriver);
        }

        // Check country routing from tenant metadata / profile if available
        $country = strtoupper($tenant->country ?? $tenant->metadata['country'] ?? '');
        if ($country && isset($this->config['routing']['countries'][$country])) {
            return $this->resolve($this->config['routing']['countries'][$country]);
        }

        $fallback = $this->config['routing']['fallback'] ?? 'stripe';

        return $this->resolve($fallback);
    }

    public function resolve(string $name): PaymentGateway
    {
        if ($this->fakeGateway !== null) {
            return $this->fakeGateway;
        }

        return match ($name) {
            'stripe' => new StripeGateway($this->config['gateways']['stripe'] ?? []),
            'razorpay' => new RazorpayGateway($this->config['gateways']['razorpay'] ?? []),
            'fake', 'mock' => $this->fakeGateway ??= new FakePaymentGateway,
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$name}"),
        };
    }
}

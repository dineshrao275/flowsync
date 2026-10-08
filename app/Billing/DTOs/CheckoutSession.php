<?php

namespace App\Billing\DTOs;

class CheckoutSession
{
    public function __construct(
        public readonly string $provider,
        public readonly string $sessionId,
        public readonly ?string $redirectUrl,
        public readonly ?string $clientSecret,
        public readonly ?string $publishableKey,
        public readonly string $currency,
        public readonly int $amountCents,
        public readonly array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'session_id' => $this->sessionId,
            'redirect_url' => $this->redirectUrl,
            'client_secret' => $this->clientSecret,
            'publishable_key' => $this->publishableKey,
            'currency' => $this->currency,
            'amount_cents' => $this->amountCents,
        ];
    }
}

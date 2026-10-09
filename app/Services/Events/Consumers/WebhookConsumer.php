<?php

namespace App\Services\Events\Consumers;

use App\Models\DomainEvent;

/** Placeholder until the webhook tables exist (next commit); the bus is already wired to it. */
class WebhookConsumer
{
    public function handle(DomainEvent $event): void {}
}

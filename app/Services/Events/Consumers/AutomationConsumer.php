<?php

namespace App\Services\Events\Consumers;

use App\Models\DomainEvent;
use App\Services\Automation\AutomationEngine;
use Illuminate\Support\Facades\Schema;

class AutomationConsumer
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function handle(DomainEvent $event): void
    {
        if (Schema::hasTable('automation_rules')) {
            $this->engine->handle($event);
        }
    }
}

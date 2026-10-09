<?php

use App\Services\Events\Consumers\WebhookConsumer;

/*
|--------------------------------------------------------------------------
| Domain events
|--------------------------------------------------------------------------
|
| Every business fact the platform records ("task.completed") goes through
| App\Services\Events\DomainEvents and is then handed to the consumers below.
| A consumer is a class with `handle(DomainEvent $event): void`; `patterns` use
| Str::is wildcards against the event type. Consumers run on the queue, one failing
| never stops another, and a failure is logged — never thrown at the request that
| caused the event.
|
*/

return [
    'consumers' => [
        'webhooks' => [
            'class' => WebhookConsumer::class,
            'patterns' => ['*'],
        ],
    ],

    // Event types integrations can subscribe to (shown in the webhook UI). Anything the
    // bus records is deliverable; this list is the documented, stable contract.
    'catalog' => [
        'task.created', 'task.updated', 'task.deleted', 'task.moved', 'task.completed',
        'task.assigned', 'task.commented', 'task.dependency_created', 'task.dependency_deleted',
        'task.attachment_created', 'task.attachment_deleted', 'task.work_logged',
    ],

    // Delivered rows older than this many days are removed by `events:prune`.
    'retention_days' => 30,
];

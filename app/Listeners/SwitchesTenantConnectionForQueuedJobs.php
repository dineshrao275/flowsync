<?php

namespace App\Listeners;

use App\Models\Tenant;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Restores the tenant connection for queued jobs (isolated tenancy).
 *
 * Every request connects the tenant DB through the SwitchTenant middleware,
 * which reads the tenant from the session. A queue worker has no session, so
 * without this listener `SwitchTenant` never runs and the default connection
 * stays on the statically configured 'tenant' connection (PostgreSQL
 * `flowsync_tenant`, a database that does not exist) — every queued job that
 * touches a model fails with "relation ... does not exist". That includes all
 * queued broadcasts: TaskSynced / CommentSynced / NotificationSent use
 * SerializesModels, which re-queries the model when the job is unserialized,
 * i.e. AFTER JobProcessing fires and before the event runs.
 *
 * The tenant id travels as a top-level job payload key written by
 * Queue::createPayloadUsing() in AppServiceProvider, so it can be read here
 * without unserializing the command.
 */
class SwitchesTenantConnectionForQueuedJobs
{
    /**
     * True while this listener owns the connection for the job being processed,
     * i.e. it connected a tenant database that has to be released afterwards.
     */
    private bool $ownsConnection = false;

    public function __construct(
        private readonly TenantDatabaseManager $tenancy,
        private readonly TenantContext $context,
    ) {}

    public function handleProcessing(JobProcessing $event): void
    {
        $tenantId = $this->tenantIdFromPayload($event->job->payload());

        if ($tenantId === null) {
            return;
        }

        try {
            $tenant = Tenant::on($this->tenancy->centralConnectionName())->find($tenantId);
        } catch (Throwable) {
            return;
        }

        // A missing tenant row leaves the default connection on the central one,
        // which is where the failure will be reported from.
        if (! $tenant) {
            return;
        }

        $this->tenancy->connect($tenant);

        // Only a real worker needs the connection released afterwards: a
        // SyncJob (QUEUE_CONNECTION=sync, and dispatchSync) runs inside the
        // request that dispatched it, so that request's connection must survive.
        $this->ownsConnection = ! $event->job instanceof SyncJob;
    }

    public function handleFinished(): void
    {
        if (! $this->ownsConnection) {
            return;
        }

        $this->ownsConnection = false;

        // The worker is long-lived: without this the next job would inherit the
        // previous job's tenant connection.
        $this->tenancy->connectSystem();
        $this->context->setImpersonating(false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tenantIdFromPayload(array $payload): ?int
    {
        $tenantId = $payload['tenant_id'] ?? null;

        return is_numeric($tenantId) ? (int) $tenantId : null;
    }
}

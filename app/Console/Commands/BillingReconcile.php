<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Reconciliation\BillingReconciler;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Compare the application with Stripe / Razorpay and repair what drifted (lost webhooks, half-finished provider calls). */
class BillingReconcile extends Command
{
    protected $signature = 'billing:reconcile {--tenant= : Central id of a single tenant}';

    protected $description = 'Reconcile tenants, subscriptions and pending payments with the payment providers';

    public function handle(BillingReconciler $reconciler): int
    {
        $only = null;
        if (($id = $this->option('tenant')) !== null) {
            $only = Tenant::find($id);
            if (! $only) {
                $this->error('No tenant matched.');

                return self::FAILURE;
            }
        }

        $r = $reconciler->run($only);
        $this->table(['Customers created', 'Trials started', 'Subscriptions mirrored', 'Payments settled', 'Failures'], [[$r['customers'], $r['trials'], $r['subscriptions'], $r['payments'], $r['failed']]]);

        return $r['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

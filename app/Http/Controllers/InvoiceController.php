<?php

namespace App\Http\Controllers;

use App\Billing\Invoices\InvoicePdf;
use App\Billing\Invoices\InvoicePresenter;
use App\Models\Invoice;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Tenant-facing invoices (P6.1): the signed-in tenant's own list and PDFs.
 * Reading needs the same grant as the payment history (`admin` or
 * `billing.view`/`billing.manage`); the tenant id comes from the session's
 * TenantContext, never from the request, so another tenant's invoice is a 404.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $invoices = Invoice::where('tenant_id', $this->tenantId())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['invoices' => $invoices->map(fn (Invoice $i) => InvoicePresenter::present($i))->values()]);
    }

    public function pdf(Request $request, int $invoice, InvoicePdf $renderer): Response
    {
        $this->authorizeView($request);

        $model = Invoice::where('tenant_id', $this->tenantId())->with('lines')->findOrFail($invoice);

        return response($renderer->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($model).'"',
        ]);
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user?->hasRole('admin') || $user?->hasPermission('billing.view') || $user?->hasPermission('billing.manage'),
            403,
            'You do not have permission to view billing information.',
        );
    }

    private function tenantId(): int
    {
        $id = $this->tenantContext->currentId();
        abort_unless($id, 404, 'No active tenant context.');

        return $id;
    }
}

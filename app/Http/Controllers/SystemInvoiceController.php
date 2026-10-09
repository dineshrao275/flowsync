<?php

namespace App\Http\Controllers;

use App\Billing\Invoices\InvoicePdf;
use App\Billing\Invoices\InvoicePresenter;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Super Admin view of every tenant's invoices (P6.1). Routes sit in the `super_admin` group. */
class SystemInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:open,paid,void'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Invoice::query()
            ->with('tenant:id,name,slug')
            ->when($filters['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'invoices' => $page->getCollection()->map(fn (Invoice $i) => InvoicePresenter::present($i))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function pdf(int $invoice, InvoicePdf $renderer): Response
    {
        $model = Invoice::with('lines')->findOrFail($invoice);

        return response($renderer->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($model).'"',
        ]);
    }
}

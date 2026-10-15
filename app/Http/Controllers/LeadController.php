<?php

namespace App\Http\Controllers;

use App\Models\MarketingLead;
use App\Services\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SA inbox for marketing-site leads. Status pipeline only — leads are
 * never edited, only worked and (when stale) deleted.
 */
class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:new,contacted,qualified,won,lost'],
            'source' => ['nullable', 'string', 'in:contact,demo'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MarketingLead::orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (! empty($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            $query->where(fn ($group) => $group
                ->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('company', 'like', $term));
        }

        $paginator = $query->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'leads' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(MarketingLead $lead): JsonResponse
    {
        return response()->json(['lead' => $lead]);
    }

    public function update(Request $request, MarketingLead $lead): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                MarketingLead::STATUS_NEW,
                MarketingLead::STATUS_CONTACTED,
                MarketingLead::STATUS_QUALIFIED,
                MarketingLead::STATUS_WON,
                MarketingLead::STATUS_LOST,
            ])],
        ]);

        $before = $lead->only(['status']);
        $lead->update(['status' => $data['status']]);

        app(PlatformAudit::class)->diff(
            $request, 'cms.lead_status_changed', 'marketing_leads', $lead->id,
            $before, $lead->only(['status']), ['email' => $lead->email],
        );

        return response()->json(['lead' => $lead->fresh()]);
    }

    public function destroy(Request $request, MarketingLead $lead): JsonResponse
    {
        app(PlatformAudit::class)->diff(
            $request, 'cms.lead_deleted', 'marketing_leads', $lead->id,
            $lead->only(['name', 'email', 'source']), null, ['email' => $lead->email],
        );

        $lead->delete();

        return response()->json(['message' => 'Lead deleted.']);
    }
}

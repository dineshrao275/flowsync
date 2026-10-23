<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebsiteRedirectStoreRequest;
use App\Models\WebsiteRedirect;
use App\Services\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CMS admin (super admin). Manual redirect rules for the public site —
 * slug renames also auto-create a 301 here (see CmsController::update),
 * which a hand edit can then override.
 */
class WebsiteRedirectController extends Controller
{
    public function index(): JsonResponse
    {
        $redirects = WebsiteRedirect::orderByDesc('id')->get(['id', 'from_path', 'to_path', 'status_code', 'hits']);

        return response()->json(['redirects' => $redirects]);
    }

    public function store(WebsiteRedirectStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $redirect = WebsiteRedirect::create([
            'from_path' => ltrim($data['from_path'], '/'),
            'to_path' => $data['to_path'],
            'status_code' => $data['status_code'] ?? 301,
        ]);

        app(PlatformAudit::class)->diff(
            $request, 'cms.redirect_created', 'website_redirects', $redirect->id,
            null, $redirect->only(['from_path', 'to_path', 'status_code']), ['from' => $redirect->from_path],
        );

        return response()->json(['redirect' => $redirect], 201);
    }

    public function destroy(Request $request, WebsiteRedirect $websiteRedirect): JsonResponse
    {
        app(PlatformAudit::class)->diff(
            $request, 'cms.redirect_deleted', 'website_redirects', $websiteRedirect->id,
            $websiteRedirect->only(['from_path', 'to_path', 'status_code']), null, ['from' => $websiteRedirect->from_path],
        );

        $websiteRedirect->delete();

        return response()->json(['message' => __('Redirect deleted.')]);
    }
}

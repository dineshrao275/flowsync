<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Controllers\Controller;
use App\Services\Hrms\MyHrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My/HRMS — the employee home aggregate.
 *
 * Self-scoped like notifications: no policy check, because every row is
 * the caller's own — a login without an employment record reads an
 * empty home, not a 403. A non-impersonating super admin 404s (D2.14):
 * platform work lives elsewhere, and an aggregate of nobody would be a
 * lie about whose home this is.
 */
class MyHrController extends Controller
{
    use DetectsPlatformUsers;

    public function __construct(private readonly MyHrService $home) {}

    public function show(Request $request): JsonResponse
    {
        abort_if($this->isPlatformSuperAdmin($request), 404);

        return response()->json($this->home->aggregate($request->user()));
    }
}

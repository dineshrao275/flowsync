<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesListSavedFilters;
use App\Models\ListSavedFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Saved filters for tenant listing pages. Sharing with the tenant needs `tenant.manage`. */
class ListSavedFilterController extends Controller
{
    use ManagesListSavedFilters;

    protected function model(): string
    {
        return ListSavedFilter::class;
    }

    protected function canShare(Request $request): bool
    {
        return Gate::forUser($request->user())->allows('permission', 'tenant.manage');
    }
}

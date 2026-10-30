<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesListSavedFilters;
use App\Models\PlatformListSavedFilter;
use Illuminate\Http\Request;

/** Saved filters for super-admin listing pages. Every platform account may share (they all see the same lists). */
class PlatformListSavedFilterController extends Controller
{
    use ManagesListSavedFilters;

    protected function model(): string
    {
        return PlatformListSavedFilter::class;
    }

    protected function canShare(Request $request): bool
    {
        return true;
    }
}

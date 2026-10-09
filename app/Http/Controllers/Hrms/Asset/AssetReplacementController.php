<?php

namespace App\Http\Controllers\Hrms\Asset;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\AssetReplaceRequest;
use App\Models\Hrms\Asset\Asset;
use App\Services\Hrms\Asset\AssetReplacement;
use Illuminate\Http\JsonResponse;

/**
 * Asset/HRMS — record that one asset replaced another (lost, damaged, faulty,
 * obsolete), optionally handing the successor to the previous holder.
 */
class AssetReplacementController extends Controller
{
    public function __construct(private readonly AssetReplacement $replacement) {}

    public function store(AssetReplaceRequest $request, Asset $asset): JsonResponse
    {
        $this->authorize('replace', $asset);

        $data = $request->validated();

        $old = $this->replacement->replace(
            $asset,
            Asset::findOrFail($data['replacement_asset_id']),
            $data['reason'],
            (bool) ($data['assign_to_holder'] ?? false),
            $request->user(),
        );

        return response()->json([
            'message' => 'Asset replaced.',
            'asset' => ['id' => $old->id, 'status' => $old->status->value, 'replaced_by_asset_id' => $old->replaced_by_asset_id],
        ]);
    }
}

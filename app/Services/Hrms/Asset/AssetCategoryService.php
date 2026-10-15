<?php

namespace App\Services\Hrms\Asset;

use App\Models\Hrms\Asset\AssetCategory;
use App\Models\User;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Asset/HRMS — the register catalogue's master data.
 *
 * The catalogue twin of `ExpenseCategoryService`: tenant-built rows create
 * freely, rename freely, and delete only while nothing references them —
 * a category with assets behind it deactivates instead, so history keeps
 * its labels.
 */
class AssetCategoryService
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): AssetCategory
    {
        $category = AssetCategory::create([
            ...$data,
            'slug' => $this->naming->uniqueSlug(AssetCategory::class, (string) $data['name']),
            'is_system' => false,
        ]);

        $this->audit->log($category, 'asset.category_created', null, [
            'slug' => $category->slug,
        ], $actor);

        return $category->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AssetCategory $category, array $data, ?User $actor = null): AssetCategory
    {
        $before = ['name' => $category->name, 'is_active' => $category->is_active];
        $category->update($data);

        $this->audit->log($category->refresh(), 'asset.category_updated', $before, [
            'name' => $category->name,
            'is_active' => $category->is_active,
        ], $actor);

        return $category->refresh();
    }

    /**
     * @throws ValidationException on a row with assets behind it
     */
    public function delete(AssetCategory $category, ?User $actor = null): void
    {
        if ($category->assets()->exists()) {
            throw ValidationException::withMessages(['form' => 'That category labels assets — deactivate it instead of deleting history.']);
        }

        $this->audit->log($category, 'asset.category_deleted', [
            'slug' => $category->slug,
        ], null, $actor);

        $category->delete();
    }
}

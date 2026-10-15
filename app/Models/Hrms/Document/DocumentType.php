<?php

namespace App\Models\Hrms\Document;

use App\Enums\Hrms\DocumentCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Document/HRMS — the tenant's document catalogue.
 *
 * Lives in the tenant database, like every other HRMS row: there is no
 * central `document_types` table and no `tenant_id` column. A slug is the
 * natural key the provisioner matches on, so a tenant that renames
 * “National ID” keeps its wording through every re-provision.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property DocumentCategory $category
 * @property bool $is_mandatory
 * @property bool $requires_expiry
 * @property int|null $retention_months
 * @property bool $is_sensitive
 * @property int $position
 * @property bool $is_active
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EmployeeDocument> $documents
 */
class DocumentType extends Model
{
    protected $table = 'document_types';

    protected $fillable = [
        'name',
        'slug',
        'category',
        'is_mandatory',
        'requires_expiry',
        'retention_months',
        'is_sensitive',
        'position',
        'is_active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'is_mandatory' => 'boolean',
            'requires_expiry' => 'boolean',
            'retention_months' => 'integer',
            'is_sensitive' => 'boolean',
            'position' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'document_type_id');
    }

    /** @param Builder<DocumentType> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<DocumentType> $query */
    public function scopeSystem(Builder $query): void
    {
        $query->where('is_system', true);
    }
}

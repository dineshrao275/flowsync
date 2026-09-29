<?php

namespace App\Models\Hrms\Payroll;

use Illuminate\Database\Eloquent\Model;

/**
 * Payroll/HRMS — one payslip render template.
 *
 * No timestamps: a template is config, not a ledger row. `content` carries
 * the tenant's customisation (`header_html`, `footer_html`,
 * `show_employer_contributions`) — absent keys fall back to the renderer's
 * built-in layout, so a null content still renders.
 */
class PayslipTemplate extends Model
{
    protected $table = 'payslip_templates';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'is_default',
        'content',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'content' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function scopeDefault($query): void
    {
        $query->where('is_default', true);
    }
}

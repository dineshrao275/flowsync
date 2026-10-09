<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use App\Services\Security\AuditChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use CentralConnection;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'action',
        'data',
        'actor_id',
        'ip_address',
    ];

    protected static function booted(): void
    {
        // P8.6: every new row joins the tamper-evident hash chain.
        static::created(fn (AuditLog $log) => app(AuditChain::class)->seal($log));
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

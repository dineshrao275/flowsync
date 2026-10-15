<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A support ticket a tenant raised with the platform team. Lives in the system DB. */
class SupportTicket extends Model
{
    use CentralConnection;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_WAITING = 'waiting_on_customer';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_WAITING, self::STATUS_RESOLVED, self::STATUS_CLOSED];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const CATEGORIES = ['billing', 'technical', 'access', 'feature_request', 'other'];

    protected $fillable = [
        'tenant_id', 'created_by_user_id', 'created_by_name', 'created_by_email', 'subject',
        'category', 'priority', 'status', 'assigned_to', 'last_activity_at', 'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public function reference(): string
    {
        return 'FS-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}

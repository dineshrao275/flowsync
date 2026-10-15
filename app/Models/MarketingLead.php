<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Contact/demo requests from the public marketing site (system DB).
 * Written by the unauthenticated contact endpoint; worked in the SA inbox.
 */
class MarketingLead extends Model
{
    use CentralConnection;

    public const SOURCE_CONTACT = 'contact';

    public const SOURCE_DEMO = 'demo';

    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    protected $fillable = [
        'name',
        'email',
        'company',
        'phone',
        'message',
        'source',
        'plan_interest',
        'status',
        'ip',
        'user_agent',
    ];
}

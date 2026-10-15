<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Manual + auto-created (slug change) redirects for the public marketing
 * site (system DB). Resolved by the route fallback in `routes/web.php`;
 * `from_path` is the path without a leading slash.
 */
class WebsiteRedirect extends Model
{
    use CentralConnection;

    protected $fillable = [
        'from_path',
        'to_path',
        'status_code',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hits' => 'integer',
        ];
    }

    public function recordHit(): void
    {
        $this->increment('hits');
    }
}

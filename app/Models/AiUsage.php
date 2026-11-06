<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    use CentralConnection;

    protected $table = 'ai_usage';

    protected $fillable = ['tenant_id', 'day', 'requests', 'input_tokens', 'output_tokens', 'document_pages', 'failures', 'cost_estimate'];

    protected function casts(): array
    {
        // `day` stays a plain Y-m-d string: a date cast writes a time part on sqlite and the unique lookup would never match.
        return ['cost_estimate' => 'float'];
    }
}

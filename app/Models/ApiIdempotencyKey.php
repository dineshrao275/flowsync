<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    protected $fillable = ['token_id', 'key', 'fingerprint', 'response_status', 'response_body'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    protected $fillable = ['token_id', 'key', 'fingerprint', 'response_status', 'response_body'];
}

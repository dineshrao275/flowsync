<?php

declare(strict_types=1);

namespace App\Models\Hrms\Document;

use Illuminate\Database\Eloquent\Model;

class LetterTemplate extends Model
{
    protected $table = 'letter_templates';

    protected $fillable = ['name', 'slug', 'document_type_slug', 'body', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

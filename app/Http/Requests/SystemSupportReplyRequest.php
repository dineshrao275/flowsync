<?php

namespace App\Http\Requests;

use App\Http\Controllers\SupportTicketController;
use Illuminate\Foundation\Http\FormRequest;

class SystemSupportReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', SupportTicketController::bodyLength(null, 10000)], 'internal' => ['sometimes', 'boolean']];
    }
}

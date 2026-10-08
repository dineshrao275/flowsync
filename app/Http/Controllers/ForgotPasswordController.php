<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function create(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        // Uniform response either way: echoing RESET_LINK_SENT vs INVALID_USER
        // (plus the status field) tells an attacker which store holds the
        // account. Whether the mail actually sends is a delivery concern, not
        // an API answer. (Tenant-aware routing of the broker itself is still
        // open — the broker runs on the central connection, see 10-security.)
        return response()->json([
            'message' => 'If that address belongs to an account, a reset link is on its way.',
        ]);
    }
}

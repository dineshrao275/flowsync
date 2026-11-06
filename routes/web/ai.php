<?php

declare(strict_types=1);

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AiConversationController;
use App\Http\Controllers\AiDocumentController;
use App\Http\Controllers\AiProposalController;
use App\Http\Controllers\AiStatusController;
use Illuminate\Support\Facades\Route;

/*
 | AI assistant. Tenant stack + the `ai` plan module + `ai.use`. Row-level access is NOT decided here:
 | every tool reads through the app's own scoped queries as the signed-in user.
 */
Route::prefix('api')->middleware(['switch_tenant', 'auth', 'tenant', 'tenant_context', 'ensure_subscribed', 'onboarding_complete', 'ensure_module:ai', 'permission:ai.use'])->group(function () {
    Route::get('ai/status', AiStatusController::class);
    Route::post('ai/chat', [AiChatController::class, 'store'])->middleware('throttle:ai-chat');
    Route::get('ai/conversations', [AiConversationController::class, 'index']);
    Route::get('ai/conversations/{conversation}', [AiConversationController::class, 'show'])->whereNumber('conversation');
    Route::delete('ai/conversations/{conversation}', [AiConversationController::class, 'destroy'])->whereNumber('conversation');

    // Document to tasks: creating tasks is a TMS action, so the TMS product gates it. Nothing is created until /confirm.
    Route::middleware('ensure_product:tms')->group(function () {
        Route::post('ai/documents', [AiDocumentController::class, 'store'])->middleware('throttle:20,1');
        Route::post('ai/proposals', [AiProposalController::class, 'store'])->middleware('throttle:ai-chat');
        Route::get('ai/proposals/{proposal}', [AiProposalController::class, 'show'])->whereUuid('proposal');
        Route::put('ai/proposals/{proposal}', [AiProposalController::class, 'update'])->whereUuid('proposal');
        Route::post('ai/proposals/{proposal}/confirm', [AiProposalController::class, 'confirm'])->whereUuid('proposal')->middleware('throttle:20,1');
        Route::post('ai/proposals/{proposal}/reject', [AiProposalController::class, 'reject'])->whereUuid('proposal');
    });
});

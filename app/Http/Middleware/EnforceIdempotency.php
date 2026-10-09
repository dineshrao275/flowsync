<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` for token writes (P2.7). The first request with a key runs; the
 * same key + same request later replays the stored answer (`Idempotent-Replayed: true`)
 * without running the action again. Reusing a key for a different request is a 422, and
 * a retry that races the first call is a 409. Server errors are never stored, so they
 * can be retried. Keys are scoped to the token and expire (`events:prune`).
 */
class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get('api_token');
        $key = $request->header('Idempotency-Key');

        if ($request->isMethodSafe() || $key === null || ! $token instanceof ApiToken) {
            return $next($request);
        }

        if (strlen($key) < 8 || strlen($key) > 120) {
            return response()->json(['message' => 'The Idempotency-Key header must be 8 to 120 characters.'], 422);
        }

        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        try {
            $record = ApiIdempotencyKey::create(['token_id' => $token->id, 'key' => $key, 'fingerprint' => $fingerprint]);
        } catch (QueryException) {
            return $this->replay(ApiIdempotencyKey::where('token_id', $token->id)->where('key', $key)->first(), $fingerprint);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            $record->delete();
        } else {
            $record->update(['response_status' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        }

        return $response;
    }

    private function replay(?ApiIdempotencyKey $record, string $fingerprint): Response
    {
        if (! $record || $record->fingerprint !== $fingerprint) {
            return response()->json(['message' => 'This Idempotency-Key was already used for a different request.'], 422);
        }

        if ($record->response_status === null) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.'], 409);
        }

        return response($record->response_body, $record->response_status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }
}

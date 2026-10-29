<?php
namespace App\Http\Middleware;

use App\Support\Api;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Honors the "Idempotency-Key" header on retry-prone POST endpoints.
 * Same key + same body  -> returns the original response (no duplicate record).
 * Same key + other body -> 422.   Same key while first request still running -> 409.
 * Only successful (2xx) responses are remembered, so a failed request can be retried with the same key.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return $next($request);
        }
        if (strlen($key) > 100) {
            return Api::error('VALIDATION_ERROR', 'Idempotency-Key is too long (max 100 characters).', 422);
        }

        $userId = $request->user()->id;
        $hash   = hash('sha256', $request->method() . '|' . $request->path() . '|' . $request->getContent());

        try {
            DB::table('idempotency_keys')->insert([
                'user_id' => $userId, 'idem_key' => $key, 'request_hash' => $hash, 'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('idem_key', $key)->first();

            if ($row->request_hash !== $hash) {
                return Api::error('IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used with a different request.', 422);
            }
            if ($row->response_status === null) {
                return Api::error('REQUEST_IN_PROGRESS', 'The original request is still being processed.', 409);
            }
            return response()->json(json_decode($row->response_body, true), $row->response_status)
                ->header('Idempotent-Replay', 'true');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where('user_id', $userId)->where('idem_key', $key)->delete();
            throw $e;
        }

        $q = DB::table('idempotency_keys')->where('user_id', $userId)->where('idem_key', $key);
        if ($response->isSuccessful()) {
            $q->update(['response_status' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        } else {
            $q->delete();
        }
        return $response;
    }
}

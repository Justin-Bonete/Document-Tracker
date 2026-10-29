<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Usage: ->middleware('permission:documents.create')  (all listed codes required) */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$codes)
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            return $this->deny(401, 'UNAUTHENTICATED', 'Authentication required.', $request);
        }
        foreach ($codes as $code) {
            if (! $user->hasPermission($code)) {
                return $this->deny(403, 'FORBIDDEN', 'You do not have permission to do this.', $request);
            }
        }
        return $next($request);
    }

    private function deny(int $status, string $code, string $msg, Request $r)
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $msg],
            'meta'  => ['request_id' => $r->attributes->get('request_id')],
        ], $status);
    }
}

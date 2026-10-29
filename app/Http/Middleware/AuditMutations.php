<?php
namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;

class AuditMutations
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $response->getStatusCode() < 400
            && $request->user()) {
            AuditLogger::record(
                'http.' . strtolower($request->method()),
                'route',
                null,
                null,
                ['path' => $request->path(), 'route' => $request->route()?->getName()],
            );
        }
        return $response;
    }
}

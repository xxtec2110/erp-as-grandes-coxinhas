<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string $permission, ?string $locationParameter = null): Response
    {
        $location = $locationParameter === null ? null : $request->route($locationParameter);
        if (is_object($location) && isset($location->location_id)) {
            $location = $location->location_id;
        }
        $this->authorization->authorize($request->user(), $permission, $location);

        return $next($request);
    }
}

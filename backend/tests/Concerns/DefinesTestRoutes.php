<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Registers throwaway routes under /api/v1/_test with the real `api`
 * middleware group, so foundation behaviour is tested exactly as production
 * endpoints will experience it.
 */
trait DefinesTestRoutes
{
    /**
     * @param  list<string>  $middleware
     */
    protected function apiRoute(string $method, string $uri, Closure $action, array $middleware = []): RoutingRoute
    {
        $route = Route::middleware(array_merge(['api'], $middleware))
            ->prefix('api/v1/_test')
            ->match([$method], $uri, $action);

        app('router')->getRoutes()->refreshNameLookups();

        return $route;
    }
}

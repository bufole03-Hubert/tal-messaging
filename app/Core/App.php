<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuditMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\MiddlewareInterface;
use App\Middleware\PermissionMiddleware;
use App\Middleware\SessionMiddleware;
use App\Middleware\TenantMiddleware;

final class App
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        (require BASE_PATH . '/config/routes.php')($this->router);
        foreach (glob(BASE_PATH . '/app/Modules/*/routes.php') ?: [] as $file) {
            (require $file)($this->router);
        }
    }

    public function run(): void
    {
        try {
            $request = Request::fromGlobals();
        } catch (\Throwable $e) {
            ErrorHandler::render($e, Request::current())->send();
            return;
        }

        try {
            $response = $this->handle($request);
        } catch (\Throwable $e) {
            $response = ErrorHandler::render($e, $request);
        }
        $response->send();
    }

    public function handle(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            throw ApiException::notFound('Route introuvable.');
        }
        $route = $match['route'];
        $request->params = $match['params'];
        $request->attributes['route'] = $route['name'];

        $core = function (Request $r) use ($route): Response {
            [$class, $method] = $route['handler'];
            $result = (new $class())->$method($r, $r->params);
            return $result instanceof Response ? $result : Response::data((array) $result);
        };

        $stack = array_merge(['session'], $route['mw']);
        $pipeline = array_reduce(
            array_reverse($stack),
            fn(callable $next, string $spec): callable => fn(Request $r): Response => $this->resolve($spec)->handle($r, $next),
            $core
        );
        return $pipeline($request);
    }

    private function resolve(string $spec): MiddlewareInterface
    {
        [$alias, $args] = array_pad(explode(':', $spec, 2), 2, '');
        return match ($alias) {
            'session' => new SessionMiddleware(),
            'auth' => new AuthMiddleware(),
            'tenant' => new TenantMiddleware(),
            'csrf' => new CsrfMiddleware(),
            'guest' => new GuestMiddleware(),
            'audit' => new AuditMiddleware(),
            'perm' => new PermissionMiddleware(array_filter(explode(',', $args))),
            default => throw new \LogicException('Middleware inconnu : ' . $alias),
        };
    }
}

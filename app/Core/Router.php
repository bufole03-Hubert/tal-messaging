<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:array{0:class-string,1:string},mw:array<int,string>,name:string}> */
    private array $routes = [];
    /** @var array<int,array<int,string>> */
    private array $stack = [];

    /** @param array<int,string> $middleware */
    public function group(array $middleware, callable $fn): void
    {
        $this->stack[] = $middleware;
        $fn($this);
        array_pop($this->stack);
    }

    /**
     * Placeholders : {id} (chiffres), {name} (segment), {name:regex}.
     * @param array{0:class-string,1:string} $handler
     * @param array<int,string> $mw
     */
    public function add(string $method, string $pattern, array $handler, string $name, array $mw = []): void
    {
        $regex = preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}#',
            static fn(array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? ($m[1] === 'id' ? '\d+' : '[^/]+')) . ')',
            $pattern
        );
        $all = [];
        foreach ($this->stack as $s) {
            $all = array_merge($all, $s);
        }
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'mw' => array_merge($all, $mw),
            'name' => $name,
        ];
    }

    /** @param array<int,string> $mw */
    public function get(string $p, array $h, string $n, array $mw = []): void { $this->add('GET', $p, $h, $n, $mw); }
    /** @param array<int,string> $mw */
    public function post(string $p, array $h, string $n, array $mw = []): void { $this->add('POST', $p, $h, $n, $mw); }
    /** @param array<int,string> $mw */
    public function put(string $p, array $h, string $n, array $mw = []): void { $this->add('PUT', $p, $h, $n, $mw); }
    /** @param array<int,string> $mw */
    public function delete(string $p, array $h, string $n, array $mw = []): void { $this->add('DELETE', $p, $h, $n, $mw); }

    /** @return array{route:array<string,mixed>,params:array<string,string>}|null */
    public function match(string $method, string $path): ?array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['route' => $route, 'params' => $params];
        }
        if ($pathMatched) {
            throw new ApiException('METHOD_NOT_ALLOWED', 'Méthode non autorisée.', 405);
        }
        return null;
    }
}

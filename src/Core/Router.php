<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $path = rtrim($path, '/') ?: '/';
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            Response::json([
                'error' => 'Not Found',
                'method' => $method,
                'path' => $path,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'server_debug' => $_SERVER
            ], 404);
            return;
        }

        $handler($request);
    }
}

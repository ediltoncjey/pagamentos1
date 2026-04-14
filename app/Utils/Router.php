<?php

declare(strict_types=1);

namespace App\Utils;

use Closure;
use Throwable;

final class Router
{
    /**
     * @var array<int, array{method:string,path:string,handler:mixed,middlewares:array<int,mixed>}>
     */
    private array $routes = [];

    /**
     * @var array<int, mixed>
     */
    private array $globalMiddlewares = [];

    public function __construct(
        private readonly Container $container,
        private readonly Logger $logger,
    ) {
    }

    public function get(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function add(string $method, string $path, mixed $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $this->normalizePath($path),
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function addGlobalMiddleware(mixed $middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $params = $this->matchPath($route['path'], $request->path());
            if ($params === null) {
                continue;
            }

            $requestWithParams = $request->withRouteParams($params);
            $middlewares = array_merge($this->globalMiddlewares, $route['middlewares']);
            $core = fn (Request $req): Response => $this->invokeHandler($route['handler'], $req);

            $pipeline = array_reduce(
                array_reverse($middlewares),
                function (callable $next, mixed $middleware): callable {
                    return function (Request $req) use ($middleware, $next): Response {
                        $resolved = $this->resolveMiddleware($middleware);
                        return $resolved($req, $next);
                    };
                },
                $core
            );

            try {
                return $pipeline($requestWithParams);
            } catch (Throwable $exception) {
                $this->logger->error(
                    'Unhandled route exception',
                    [
                        'path' => $requestWithParams->path(),
                        'method' => $requestWithParams->method(),
                        'exception' => $exception->getMessage(),
                    ]
                );

                return Response::json([
                    'error' => 'Internal Server Error',
                    'request_id' => $requestWithParams->header('X-Request-Id'),
                ], 500);
            }
        }

        return Response::json(['error' => 'Not Found'], 404);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $normalized = '/' . ltrim($path, '/');
        return rtrim($normalized, '/') === '' ? '/' : rtrim($normalized, '/');
    }

    /**
     * @return array<string, string>|null
     */
    private function matchPath(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        if ($regex === null) {
            return null;
        }

        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = urldecode($value);
            }
        }

        return $params;
    }

    private function invokeHandler(mixed $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            return $handler($request, $this->container);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : $this->container->make((string) $class);
            return $instance->{$method}($request);
        }

        if (is_callable($handler)) {
            return $handler($request, $this->container);
        }

        throw new \RuntimeException('Invalid route handler.');
    }

    private function resolveMiddleware(mixed $middleware): callable
    {
        if ($middleware instanceof Closure) {
            return $middleware;
        }

        if (is_string($middleware) && class_exists($middleware)) {
            $instance = $this->container->make($middleware);
            return fn (Request $request, callable $next): Response => $instance->handle($request, $next);
        }

        if (is_object($middleware) && method_exists($middleware, 'handle')) {
            return fn (Request $request, callable $next): Response => $middleware->handle($request, $next);
        }

        if (is_callable($middleware)) {
            return $middleware;
        }

        throw new \RuntimeException('Invalid middleware definition.');
    }
}

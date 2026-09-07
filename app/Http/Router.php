<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AppException;

final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:callable}> */
    private array $routes = [];
    private ?string $lastMatchedPattern = null;

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . rtrim((string) $regex, '/') . '/?$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->lastMatchedPattern = $route['pattern'];
            $response = ($route['handler'])($request, $params);
            if (!$response instanceof Response) {
                throw new AppException('Route did not return a response.', 500, 'invalid_route_response');
            }
            return $response;
        }

        if ($allowed !== []) {
            throw new AppException('The request method is not allowed for this endpoint.', 405, 'method_not_allowed', ['allowed' => array_values(array_unique($allowed))], 'errors.not-found');
        }

        throw new AppException('The requested endpoint was not found.', 404, 'not_found', [], 'errors.not-found');
    }

    public function lastMatchedPattern(): ?string
    {
        return $this->lastMatchedPattern;
    }
}

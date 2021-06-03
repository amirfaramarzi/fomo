<?php

namespace Core;

use Core\Response;

class Route
{
    protected array $routes = [];

    protected string $currentGroupPrefix = '';

    protected array $currentGroupMiddleware = [];

    public function post(string $route , array $callback): void
    {
        $this->addRoute('POST' , $route , $callback);
    }

    public function get(string $route , array $callback): void
    {
        $this->addRoute('GET' , $route , $callback);
    }

    public function patch(string $route , array $callback): void
    {
        $this->addRoute('PATCH' , $route , $callback);
    }

    public function put(string $route , array $callback): void
    {
        $this->addRoute('PUT' , $route , $callback);
    }

    public function delete(string $route , array $callback): void
    {
        $this->addRoute('DELETE' , $route , $callback);
    }

    public function group(array $parameters, callable $callback): void
    {
        $previousGroupPrefix = $this->currentGroupPrefix;
        if (isset($parameters['prefix']))
            $this->currentGroupPrefix = $previousGroupPrefix . $parameters['prefix'] . '/';

        $previousGroupMiddleware = $this->currentGroupMiddleware;
        if (isset($parameters['middleware']))
            array_push($this->currentGroupMiddleware , $parameters['middleware']);

        $callback($this);

        $this->currentGroupPrefix = $previousGroupPrefix;
        $this->currentGroupMiddleware = $previousGroupMiddleware;
    }

    protected function addRoute(string $method , string $route , array $callback): void
    {
        $route = $this->currentGroupPrefix . $route;

        $route = preg_replace('/^\//','' , $route);

        $route = preg_replace('/\/\//' , '' , $route);

        $route = preg_replace('/\//' , '\\/' , $route);

        $route = preg_replace('/\{([a-zA-Z]+)\}/' , '(?<\1>[a-z0-9-]+)' , $route);

        $route = '/^\/' . $route . '\/?$/i';

        $routeParameters['method'] = $method;
        $routeParameters['controller'] = $callback[0];
        $routeParameters['action'] = $callback[1];

        if (isset($callback['middleware']))
            if (is_string($callback['middleware']))
                $routeParameters['middleware'][0] = $callback['middleware'];
            else
                $routeParameters['middleware'] = $callback['middleware'];

        if (! isset($routeParameters['middleware']))
            $routeParameters['middleware'] = [];

        if (! empty($this->currentGroupMiddleware[0]))
            if (is_array($routeParameters['middleware']))
                $routeParameters['middleware'] = array_merge($routeParameters['middleware'] , is_array($this->currentGroupMiddleware[0]) ? $this->currentGroupMiddleware[0] : $this->currentGroupMiddleware);
            else
                $this->currentGroupMiddleware[0] != null ? array_push($routeParameters['middleware'] , is_array($this->currentGroupMiddleware[0]) ? $this->currentGroupMiddleware[0] : $this->currentGroupMiddleware) : null;

        $this->routes[$route] = $routeParameters;
    }

    protected function checkMatchRoute(string $url , string $method): bool|string
    {
        foreach ($this->routes as $route => $parameters) {
            if(preg_match($route , $url , $matches) && $this->routes[$route]['method'] == $method) {
                foreach ($matches as $key => $match) {
                    if(is_string($key)) {
                        $this->routes[$route]['params'][$key] = $match;
                    }
                }
                return $route;
            }
        }
        return false;
    }

    protected function checkMatchMethod(string $route , string $method): bool
    {
        if ($this->routes[$route]['method'] == $method)
            return true;

        return false;
    }

    protected function checkExistAndAccessMiddleware(string $route): bool|Response
    {
        $middlewares = $this->routes[$route]['middleware'];
        foreach ($middlewares as $middleware){
            $middleware = new $middleware();
            $callMiddleware = $middleware->handle();
            if ($callMiddleware !== true)
                return $callMiddleware;
        }
        return true;
    }

    protected function removeVariablesOfQueryString(string $route): bool|string
    {
        $parameters = explode('?' , $route);

        if (!str_contains($parameters[0], '='))
            return $parameters[0];

        return false;
    }

    public function dispatch(string $route , string $method)
    {
        $route = $this->removeVariablesOfQueryString($route);

        if ($route === false)
            return json([
                'message' => 'error'
            ] , Response::HTTP_BAD_REQUEST);


        $checkMatch = $this->checkMatchRoute($route , $method);
        if ($checkMatch === false)
            return json([
                'message' => 'not found'
            ] , Response::HTTP_NOT_FOUND);

        $checkMethod = $this->checkMatchMethod($checkMatch , $method);
        if ($checkMethod === false)
            return json([
                'message' => 'this is route not supported this method'
            ] , Response::HTTP_METHOD_NOT_ALLOWED);


        if (! empty($this->routes[$checkMatch]['middleware']))
            $checkMiddleware = $this->checkExistAndAccessMiddleware($checkMatch);

        if (isset($checkMiddleware) && $checkMiddleware !== true){
            return $checkMiddleware;
        }

        $controller = new $this->routes[$checkMatch]['controller']();
        $method = $this->routes[$checkMatch]['action'];

        return call_user_func_array([$controller , $method] , $this->routes[$checkMatch]['params'] ?? []);
    }
}

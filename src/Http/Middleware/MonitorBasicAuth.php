<?php

namespace LaraGrep\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MonitorBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = config('laragrep.monitor.username');
        $password = config('laragrep.monitor.password');

        if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
            return $next($request);
        }

        if ($request->getUser() === $username && $request->getPassword() === $password) {
            return $next($request);
        }

        return new Response('Unauthorized.', 401, [
            'WWW-Authenticate' => 'Basic realm="LaraGrep Monitor"',
        ]);
    }
}

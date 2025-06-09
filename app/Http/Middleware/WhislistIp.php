<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WhislistIp
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $whislistIps = [
            '175.106.17.229',
            '175.106.17.227',
        ];

        if (!in_array($request->ip(), $whislistIps)) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}

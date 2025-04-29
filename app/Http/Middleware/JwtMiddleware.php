<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponseTrait;
use Closure;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class JwtMiddleware
{
    use ApiResponseTrait;
    public function handle($request, Closure $next)
    {
        // try {
        //     $user = JWTAuth::parseToken()->authenticate();
        // } catch (JWTException $e) {
        //     // return response()->json(['error' => 'Token not valid'], 401);
        //     return $this->apiResponse('','Token not valid',401);
        // }

        return $next($request);
    }
}

<?php
 
namespace Cslash\SharedSync\Http\Middleware;
 
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
 
class AuthenticateSharedSync
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $tokenFile = base_path('.sharedsync-token');
        $token = File::exists($tokenFile) ? trim(File::get($tokenFile)) : null;
 
        if (!$token || $request->header('X-SharedSync-Token') !== $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
 
        return $next($request);
    }
}

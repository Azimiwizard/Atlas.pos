<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            $user = auth('sanctum')->user();
        }
        $allowed = array_map(fn ($role) => trim($role), $roles);

        if (count($allowed) === 1 && str_contains($allowed[0], ',')) {
            $allowed = array_map('trim', explode(',', $allowed[0]));
        }

        if ($user && in_array($user->role, $allowed, true)) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}




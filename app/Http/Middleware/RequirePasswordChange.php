<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks API access for users who are still holding a temporary password.
 *
 * The SPA already redirects these users to the "set password" screen, but that
 * is only a client-side courtesy: without this middleware a flagged account can
 * keep calling the API with its token (recording payments, creating tenants,
 * reading other owners' data) simply by ignoring the redirect.
 */
class RequirePasswordChange
{
    /**
     * Endpoints a flagged user must still be able to reach: reading its own
     * profile, clearing the flag, and signing out.
     */
    protected array $allowed = [
        'profile',
        'profile/password',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        // Request::is() matches the full path, which carries the "api" segment,
        // so compare against the path with that prefix removed.
        $path = Str::after($request->path(), 'api/');

        if (in_array($path, $this->allowed, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code' => 'password_change_required',
            'message' => 'You must change your temporary password before continuing.',
        ], 403);
    }
}

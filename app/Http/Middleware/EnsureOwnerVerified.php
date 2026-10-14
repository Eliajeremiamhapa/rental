<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owners may only reach the owner area once a system administrator has approved
 * their verification. Owners still waiting (or rejected) are told to finish
 * verification rather than being handed a generic authorization error.
 */
class EnsureOwnerVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->isOwner() && ! $user->isVerifiedOwner()) {
            return response()->json([
                'success' => false,
                'code' => 'owner_not_verified',
                'message' => 'Your owner account is pending verification. Please submit your documents and wait for an administrator to approve them.',
            ], 403);
        }

        return $next($request);
    }
}

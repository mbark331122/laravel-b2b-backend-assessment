<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks deactivated company members from authenticated API access.
 * Platform admins (no company) remain allowed.
 */
class EnsureActiveCompanyMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            // Re-read is_active from the database; test/auth caches can be stale.
            $active = User::query()->whereKey($user->id)->value('is_active');

            if ($user->company_id !== null && ! (bool) $active) {
                return response()->json([
                    'message' => 'This membership is deactivated.',
                ], 403);
            }
        }

        return $next($request);
    }
}

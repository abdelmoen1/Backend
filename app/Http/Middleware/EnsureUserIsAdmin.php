<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** مسارات إدارة المنصة: الدور admin فقط، ويُتحقق منه في الخادم لكل طلب. */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة مخصصة لإدارة المنصة.',
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** الحساب الذي أوقفه مدير المنصة يُرفض على كل مسار محمي، حتى برمز وصول صادر قبل الإيقاف. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            return response()->json([
                'success' => false,
                'code' => 'account_deactivated',
                'message' => 'تم إيقاف هذا الحساب. تواصل مع إدارة المنصة.',
            ], 403);
        }

        return $next($request);
    }
}

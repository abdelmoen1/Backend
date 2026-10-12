<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * معرّف لكل طلب يُضاف تلقائيًا إلى سجلات الخادم ويُعاد في ترويسة X-Request-Id، لربط ما يظهر
 * في أدوات المطور بسطر السجل المقابل دون كشف أي تفاصيل داخلية.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id');
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}

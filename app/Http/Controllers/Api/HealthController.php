<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * فحص صحة الخدمة لمنصة الاستضافة: 200 فقط إذا كانت قاعدة البيانات تجيب ومخطط الجداول مُرحّل.
 * لا يكشف اسم المضيف أو رسالة الخطأ؛ التفاصيل تذهب إلى السجل.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->select('select 1');
            $migrated = DB::connection()->getSchemaBuilder()->hasTable('migrations')
                && DB::table('migrations')->exists();
        } catch (Throwable $exception) {
            Log::error('Health check failed: database unreachable.', ['exception' => $exception::class]);

            return response()->json(['status' => 'error', 'database' => 'unreachable'], 503);
        }

        if (! $migrated) {
            return response()->json(['status' => 'error', 'database' => 'not_migrated'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'database' => 'ok',
            'driver' => DB::connection()->getDriverName(),
            'release' => config('app.release'),
        ]);
    }
}

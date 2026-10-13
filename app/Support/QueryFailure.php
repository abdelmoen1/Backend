<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * سياق سجل آمن لفشل استعلام. رسالة QueryException الافتراضية تحوي الاستعلام بقيمه، ومنها أسئلة التقييم ومعاييره
 * الخاصة وإجابات الطلاب؛ هنا يبقى ما يكفي للتشخيص فقط: حالة SQL ورمز المحرك ونص خطئه بلا قيم، وموضع الاستدعاء.
 */
class QueryFailure
{
    /** @return array{connection: ?string, sqlstate: string, driver_code: int|string|null, error: string, origin: ?string} */
    public static function context(QueryException $exception): array
    {
        $driverMessage = is_string($exception->errorInfo[2] ?? null)
            ? $exception->errorInfo[2]
            : (string) ($exception->getPrevious()?->getMessage() ?? '');

        return [
            'connection' => $exception->getConnectionName(),
            'sqlstate' => (string) ($exception->errorInfo[0] ?? $exception->getCode()),
            'driver_code' => $exception->errorInfo[1] ?? null,
            'error' => mb_substr(self::redact($driverMessage), 0, 300),
            'origin' => self::origin($exception),
        ];
    }

    /** رسائل MySQL تقتبس أحيانًا جزءًا من القيمة (Incorrect string value: '…'، Duplicate entry '…')؛ أسماء الأعمدة تبقى. */
    public static function redact(string $message): string
    {
        return preg_replace("/\\b(value|entry)(\\s*:)?\\s*'(?:[^'\\\\]|\\\\.)*'/iu", "$1$2 '[redacted]'", $message) ?? '';
    }

    /** أول موضع في كود التطبيق (خارج vendor) بدل تتبع المكدس الكامل. */
    private static function origin(QueryException $exception): ?string
    {
        $app = str_replace('\\', '/', app_path()).'/';
        foreach ($exception->getTrace() as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if (str_starts_with($file, $app)) {
                return 'app/'.substr($file, strlen($app)).':'.($frame['line'] ?? '?');
            }
        }

        return null;
    }
}

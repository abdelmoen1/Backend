<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * تهيئة مدير المنصة بأمان: يرقّي حسابًا قائمًا فقط (أنشئه أولًا بالتسجيل العادي بكلمة مرور قوية).
 * التسجيل العام لا يقبل الدور admin. لا يُنشئ حسابات ولا يطبع أي كلمات مرور.
 *
 *   php artisan gotrainee:promote-admin admin@example.com
 *   ADMIN_PROMOTE_EMAILS=a@x.com,b@y.com (في entrypoint عند الإقلاع، idempotent)
 */
class PromoteAdminCommand extends Command
{
    protected $signature = 'gotrainee:promote-admin {emails* : بريد حساب قائم أو أكثر}';

    protected $description = 'Promote existing user account(s) to Platform Admin.';

    public function handle(): int
    {
        $status = self::SUCCESS;
        foreach ($this->argument('emails') as $raw) {
            foreach (array_filter(array_map('trim', explode(',', $raw))) as $email) {
                $user = User::query()->where('email', mb_strtolower($email))->orWhere('email', $email)->first();
                if (! $user) {
                    $this->warn("No account for {$email}; register it first, then rerun.");
                    $status = self::FAILURE;

                    continue;
                }
                if ($user->isAdmin()) {
                    $this->line("{$email} is already an admin.");

                    continue;
                }

                $previous = $user->role;
                $user->forceFill(['role' => User::ROLE_ADMIN, 'is_active' => true, 'deactivated_at' => null])->save();
                AuditLog::record(null, 'user.promoted_to_admin', $user, ['previous_role' => $previous]);
                $this->info("{$email} promoted to admin (was {$previous}).");
            }
        }

        return $status;
    }
}

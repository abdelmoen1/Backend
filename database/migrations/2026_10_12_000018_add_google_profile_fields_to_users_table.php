<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * حقول تسجيل الدخول عبر Google: avatar و email_verified_at. google_id وجعل password اختياريًا أُضيفا
     * بتعديل migration إنشاء جدول users، فقاعدة بيانات أُنشئت قبل ذلك التعديل لا تحتويهما؛ هنا يُضافان فقط
     * إن كانا مفقودين، فالتشغيل آمن على قاعدة جديدة أو قديمة.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'google_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('google_id')->nullable()->after('role');
                $table->unique('google_id', 'users_google_id_unique');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            // حسابات Google بلا كلمة مرور
            $table->string('password')->nullable()->change();

            if (! Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar', 1024)->nullable()->after('google_id');
            }
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }
        });
    }

    /** google_id و password الاختياري جزء من migration إنشاء الجدول، فلا يُتراجع عنهما هنا. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'email_verified_at']);
        });
    }
};

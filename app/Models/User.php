<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_STUDENT = 'student';

    public const ROLE_ORGANIZATION = 'organization';

    /** مدير المنصة: لا يُنشأ بالتسجيل العام؛ يُرقّى حساب قائم عبر أمر gotrainee:promote-admin فقط */
    public const ROLE_ADMIN = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'google_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * ينشئ الملف المرتبط بدور المستخدم (طالب أو مؤسسة) إن لم يكن موجودًا.
     */
    public function ensureRoleProfile(): void
    {
        if ($this->role === 'student') {
            StudentProfile::firstOrCreate(['user_id' => $this->id]);
        }

        if ($this->role === 'organization') {
            OrganizationProfile::firstOrCreate(['user_id' => $this->id]);
        }
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function organizationProfile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** الحساب الموقوف لا يصل لأي مسار محمي (يُرفض الدخول وتُرفض رموزه القائمة) */
    public function isActive(): bool
    {
        return $this->is_active !== false;
    }

    public function leaderAssignments(): HasMany
    {
        return $this->hasMany(ProjectLeaderAssignment::class, 'leader_user_id');
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'empresa_id',
    'sucursal_id',
    'nombre',
    'email',
    'password_hash',
    'estado',
    'ultimo_acceso_at',
    'ultimo_acceso_ip',
])]
#[Hidden(['password_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'usuario_roles',
            'usuario_id',
            'rol_id'
        )->withTimestamps();
    }

    /**
     * @return list<string>
     */
    public function roleCodes(): array
    {
        return $this->roles()->pluck('codigo')->values()->all();
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return $this->roles()
            ->with('permissions:id,codigo')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('codigo'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionCodes(), true);
    }

    public function isActive(): bool
    {
        return $this->estado === self::STATUS_ACTIVE;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ultimo_acceso_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }
}

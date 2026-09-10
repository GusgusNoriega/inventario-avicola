<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\ReceptionSyncToken;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceptionSyncTokenService
{
    public const MODULE = 'MODULO_RECEPCION_POLLO_VIVO';

    /** @return array{token: ReceptionSyncToken, plain_text_token: string} */
    public function issue(
        User $user,
        Sucursal $branch,
        string $deviceName,
        ?string $deviceId = null,
        int $expiresInDays = 90,
    ): array {
        abort_unless($this->actorCanAccessBranch($user, $branch), 403,
            'El usuario no tiene acceso activo a esta sucursal de recepción.');

        $deviceName = trim($deviceName);
        if ($deviceName === '' || mb_strlen($deviceName) > 120) {
            throw ValidationException::withMessages(['device_name' => 'Indica un nombre de dispositivo de hasta 120 caracteres.']);
        }
        if ($expiresInDays < 1 || $expiresInDays > 365) {
            throw ValidationException::withMessages(['expires_in_days' => 'La vigencia debe estar entre 1 y 365 días.']);
        }
        if ($deviceId !== null && ! Str::isUuid($deviceId)) {
            throw ValidationException::withMessages(['device_id' => 'El identificador del dispositivo debe ser un UUID.']);
        }

        $plainTextToken = 'rpv_'.bin2hex(random_bytes(32));
        $token = ReceptionSyncToken::query()->create([
            'empresa_id' => $user->empresa_id,
            'sucursal_id' => $branch->id,
            'user_id' => $user->id,
            'device_id' => strtolower($deviceId ?? (string) Str::uuid()),
            'device_name' => $deviceName,
            'token_prefix' => substr($plainTextToken, 0, 12),
            'token_hash' => hash('sha256', $plainTextToken),
            'password_fingerprint' => $this->passwordFingerprint($user),
            'expires_at' => now()->addDays($expiresInDays),
        ]);

        return ['token' => $token, 'plain_text_token' => $plainTextToken];
    }

    public function revoke(ReceptionSyncToken $token): void
    {
        ReceptionSyncToken::query()->whereKey($token->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function actorCanAccessBranch(User $user, Sucursal $branch): bool
    {
        return $user->isActive()
            && ! $user->debe_cambiar_password
            && $user->empresa?->estado === Empresa::STATUS_ACTIVE
            && $branch->estado === 'ACTIVO'
            && (int) $branch->empresa_id === (int) $user->empresa_id
            && ($user->sucursal_id === null || (int) $user->sucursal_id === (int) $branch->id)
            && app(ModuleAvailabilityService::class)->isEnabled(self::MODULE)
            && $user->hasModule(self::MODULE);
    }

    public function passwordFingerprint(User $user): string
    {
        return hash('sha256', $user->getAuthPassword());
    }
}

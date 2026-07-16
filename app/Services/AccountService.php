<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function __construct(
        private readonly AccessAuditService $audit,
        private readonly AccessSessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, array $data, ?string $ip = null): User
    {
        return DB::transaction(function () use ($actor, $data, $ip): User {
            $user = User::query()
                ->where('empresa_id', $actor->empresa_id)
                ->lockForUpdate()
                ->findOrFail($actor->id);
            $before = $this->snapshot($user);
            $attributes = [];

            foreach ([
                'name' => 'nombre',
                'email' => 'email',
                'branch_id' => 'sucursal_id',
            ] as $input => $attribute) {
                if (array_key_exists($input, $data)) {
                    $attributes[$attribute] = $data[$input];
                }
            }

            if ($attributes !== []) {
                $user->forceFill($attributes)->save();
            }

            $this->audit->record(
                (int) $user->empresa_id,
                (int) $user->id,
                'usuario',
                $user->id,
                'ACTUALIZAR_CUENTA',
                $before,
                $this->snapshot($user),
                $ip,
            );

            return $user->load('roles.permissions:id,codigo');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function changePassword(
        User $actor,
        array $data,
        ?int $currentTokenId,
        ?string $currentSessionId,
        ?string $ip = null
    ): User {
        return DB::transaction(function () use (
            $actor,
            $data,
            $currentTokenId,
            $currentSessionId,
            $ip
        ): User {
            $user = User::query()
                ->where('empresa_id', $actor->empresa_id)
                ->lockForUpdate()
                ->findOrFail($actor->id);

            if (! Hash::check($data['current_password'], (string) $user->password_hash)) {
                throw ValidationException::withMessages([
                    'current_password' => 'La contrasena actual es incorrecta.',
                ]);
            }

            $user->forceFill([
                'password_hash' => Hash::make($data['password']),
                'debe_cambiar_password' => false,
            ])->save();

            if ((bool) ($data['revoke_other_sessions'] ?? true)) {
                $this->sessions->revokeOthers($user, $currentTokenId, $currentSessionId);
            }

            $this->audit->record(
                (int) $user->empresa_id,
                (int) $user->id,
                'usuario',
                $user->id,
                'CAMBIAR_PASSWORD',
                null,
                ['must_change_password' => false],
                $ip,
            );

            return $user->load('roles.permissions:id,codigo');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->nombre,
            'email' => $user->email,
            'branch_id' => $user->sucursal_id,
        ];
    }
}

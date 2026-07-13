<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FinancialAuditService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        int $companyId,
        ?int $userId,
        string $entity,
        int|string $entityId,
        string $action,
        ?array $before,
        ?array $after,
        ?string $ip = null,
    ): void {
        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $userId,
            'entidad' => $entity,
            'entidad_id' => (string) $entityId,
            'accion' => $action,
            'datos_antes' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'datos_despues' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'direccion_ip' => $ip,
            'created_at' => now(),
        ]);
    }
}

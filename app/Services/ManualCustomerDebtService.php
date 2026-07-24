<?php

namespace App\Services;

use App\Models\User;
use App\Support\FinancialMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualCustomerDebtService
{
    private const ORIGIN_PREFIX = 'DEUDA_ANTERIOR_CLIENTE:';

    public function __construct(
        private readonly FinancialAuditService $audit,
    ) {}

    /**
     * Registra una deuda histórica como cuenta por cobrar, sin crear un
     * movimiento de caja o banco.
     *
     * @param  array<string, mixed>  $data
     * @return array{document_id: int, idempotent: bool}
     */
    public function register(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor);
        $originKey = self::ORIGIN_PREFIX.$data['idempotency_key'];

        try {
            return DB::transaction(
                fn (): array => $this->registerInTransaction(
                    $companyId,
                    $actor,
                    $data,
                    $originKey,
                    $ip,
                ),
                3,
            );
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($companyId, $data, $originKey, $exception): array {
                $existing = DB::table('comprobantes')
                    ->where('empresa_id', $companyId)
                    ->where('origen_clave', $originKey)
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    throw $exception;
                }

                $this->assertSameRequest($existing, $data);

                return [
                    'document_id' => (int) $existing->id,
                    'idempotent' => true,
                ];
            }, 3);
        }
    }

    /** @return array<string, mixed> */
    public function document(int $companyId, int $documentId): array
    {
        $document = DB::table('comprobantes as comprobante')
            ->join('terceros as cliente', 'cliente.id', '=', 'comprobante.tercero_id')
            ->leftJoin('comprobante_detalles as detalle', 'detalle.comprobante_id', '=', 'comprobante.id')
            ->where('comprobante.empresa_id', $companyId)
            ->where('comprobante.id', $documentId)
            ->select([
                'comprobante.*',
                'cliente.nombre_razon_social as cliente_nombre',
                'cliente.numero_documento as cliente_documento',
                'detalle.descripcion as detalle',
            ])
            ->first();

        abort_unless($document, 404, 'La deuda anterior no fue encontrada.');

        return [
            'id' => (int) $document->id,
            'lado' => 'CXC',
            'operacion' => $document->operacion,
            'naturaleza' => $document->naturaleza,
            'tipo_documento' => $document->tipo_documento,
            'codigo' => $document->codigo,
            'fecha_emision' => $document->fecha_emision,
            'fecha_vencimiento' => $document->fecha_vencimiento,
            'moneda' => $document->moneda,
            'total' => FinancialMoney::normalize((string) $document->total),
            'saldo_pendiente' => FinancialMoney::normalize((string) $document->saldo_pendiente),
            'estado' => $document->estado,
            'detalle' => $document->detalle,
            'cliente' => [
                'id' => (int) $document->tercero_id,
                'nombre' => $document->cliente_nombre,
                'numero_documento' => $document->cliente_documento,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{document_id: int, idempotent: bool}
     */
    private function registerInTransaction(
        int $companyId,
        User $actor,
        array $data,
        string $originKey,
        ?string $ip,
    ): array {
        $existing = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('origen_clave', $originKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $this->assertSameRequest($existing, $data);

            return [
                'document_id' => (int) $existing->id,
                'idempotent' => true,
            ];
        }

        $client = DB::table('terceros as cliente')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'cliente.id')
            ->where('cliente.id', $data['cliente_id'])
            ->where('cliente.empresa_id', $companyId)
            ->where('cliente.estado', 'ACTIVO')
            ->where('rol.rol', 'CLIENTE')
            ->select('cliente.*')
            ->lockForUpdate()
            ->first();

        if (! $client) {
            throw ValidationException::withMessages([
                'cliente_id' => 'El cliente no existe, está inactivo o pertenece a otra empresa.',
            ]);
        }

        $now = now();
        $documentId = DB::table('comprobantes')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => $client->id,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'SALDO_ANTERIOR',
            'codigo' => 'TMP-'.str_replace('-', '', $data['idempotency_key']),
            'origen_codigo' => 'MANUAL',
            'origen_clave' => $originKey,
            'fecha_emision' => $data['fecha_emision'],
            'fecha_vencimiento' => $data['fecha_emision'],
            'moneda' => $data['moneda'],
            'subtotal' => $data['importe'],
            'impuesto' => '0.00',
            'total' => $data['importe'],
            'saldo_pendiente' => $data['importe'],
            'estado' => 'PENDIENTE',
            'contraparte_tipo_documento_snapshot' => $client->tipo_documento,
            'contraparte_numero_documento_snapshot' => $client->numero_documento,
            'contraparte_nombre_snapshot' => $client->nombre_razon_social,
            'contraparte_direccion_snapshot' => $client->direccion,
            'created_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $code = 'DA-'.str_pad((string) $documentId, 8, '0', STR_PAD_LEFT);
        DB::table('comprobantes')->where('id', $documentId)->update(['codigo' => $code]);

        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $documentId,
            'tipo_pollo_id' => null,
            'descripcion' => $data['detalle'],
            'cantidad_aves' => null,
            'peso_neto_kg' => null,
            'precio_kg' => null,
            'subtotal' => $data['importe'],
            'created_at' => $now,
        ]);

        $after = (array) DB::table('comprobantes')->where('id', $documentId)->first();
        $after['detalle'] = $data['detalle'];
        $this->audit->record(
            $companyId,
            $actor->id,
            'comprobantes',
            $documentId,
            'REGISTRAR_DEUDA_ANTERIOR',
            null,
            $after,
            $ip,
        );

        return [
            'document_id' => (int) $documentId,
            'idempotent' => false,
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertSameRequest(object $document, array $data): void
    {
        $detail = DB::table('comprobante_detalles')
            ->where('comprobante_id', $document->id)
            ->value('descripcion');
        $same = (int) $document->tercero_id === (int) $data['cliente_id']
            && (string) $document->fecha_emision === (string) $data['fecha_emision']
            && (string) $document->moneda === (string) $data['moneda']
            && FinancialMoney::compare((string) $document->total, (string) $data['importe']) === 0
            && (string) $detail === (string) $data['detalle'];

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue usada para registrar otra deuda.',
            ]);
        }
    }

    private function assertActor(int $companyId, User $actor): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId && $actor->isActive(),
            403,
            'Usuario no autorizado para esta empresa.',
        );
        abort_unless(
            $actor->hasPermission('SALDOS_AJUSTAR'),
            403,
            'Se requiere el permiso SALDOS_AJUSTAR.',
        );
    }
}

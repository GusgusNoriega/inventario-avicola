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
        ?object $productDispatchBranch = null,
    ): array {
        $this->assertActor($companyId, $actor, $productDispatchBranch);
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

    /** @param array<string, mixed> $filters */
    public function list(int $companyId, array $filters): array
    {
        $query = DB::table('comprobantes as comprobante')
            ->join('terceros as cliente', 'cliente.id', '=', 'comprobante.tercero_id')
            ->leftJoin('comprobante_detalles as detalle', 'detalle.comprobante_id', '=', 'comprobante.id')
            ->where('comprobante.empresa_id', $companyId)
            ->where('comprobante.origen_clave', 'like', self::ORIGIN_PREFIX.'%')
            ->when($filters['estado'] ?? null, fn ($query, string $status) => $query->where('comprobante.estado', $status))
            ->when($filters['cliente_id'] ?? null, fn ($query, int|string $id) => $query->where('comprobante.tercero_id', $id))
            ->when($filters['moneda'] ?? null, fn ($query, string $currency) => $query->where('comprobante.moneda', $currency))
            ->when($filters['desde'] ?? null, fn ($query, string $date) => $query->whereDate('comprobante.fecha_emision', '>=', $date))
            ->when($filters['hasta'] ?? null, fn ($query, string $date) => $query->whereDate('comprobante.fecha_emision', '<=', $date))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function ($query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where(function ($nested) use ($search): void {
                    $nested->where('comprobante.codigo', 'like', "%{$search}%")
                        ->orWhere('cliente.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhere('cliente.numero_documento', 'like', "%{$search}%")
                        ->orWhere('detalle.descripcion', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('comprobante.fecha_emision')
            ->orderByDesc('comprobante.id')
            ->select([
                'comprobante.id',
                'comprobante.codigo',
                'comprobante.fecha_emision',
                'comprobante.moneda',
                'comprobante.total',
                'comprobante.saldo_pendiente',
                'comprobante.estado',
                'comprobante.anulada_at',
                'comprobante.motivo_anulacion',
                'cliente.id as cliente_id',
                'cliente.nombre_razon_social as cliente_nombre',
                'cliente.numero_documento as cliente_documento',
                'detalle.descripcion as detalle',
            ]);

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($paginator->items())->map(fn (object $document): array => [
                'id' => (int) $document->id,
                'codigo' => $document->codigo,
                'fecha_emision' => $document->fecha_emision,
                'moneda' => $document->moneda,
                'total' => FinancialMoney::normalize((string) $document->total),
                'saldo_pendiente' => FinancialMoney::normalize((string) $document->saldo_pendiente),
                'estado' => $document->estado,
                'detalle' => $document->detalle,
                'anulada_at' => $document->anulada_at,
                'motivo_anulacion' => $document->motivo_anulacion,
                'cliente' => [
                    'id' => (int) $document->cliente_id,
                    'nombre' => $document->cliente_nombre,
                    'numero_documento' => $document->cliente_documento,
                ],
                'puede_editar' => $document->estado !== 'ANULADO',
                'puede_anular' => $document->estado === 'PENDIENTE'
                    && FinancialMoney::compare((string) $document->saldo_pendiente, (string) $document->total) === 0,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
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
            'anulada_at' => $document->anulada_at,
            'motivo_anulacion' => $document->motivo_anulacion,
            'puede_editar' => $document->estado !== 'ANULADO',
            'puede_anular' => $document->estado === 'PENDIENTE'
                && FinancialMoney::compare((string) $document->saldo_pendiente, (string) $document->total) === 0,
            'cliente' => [
                'id' => (int) $document->tercero_id,
                'nombre' => $document->cliente_nombre,
                'numero_documento' => $document->cliente_documento,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(
        int $companyId,
        User $actor,
        int $documentId,
        array $data,
        ?string $ip = null,
        ?object $productDispatchBranch = null,
    ): void {
        $this->assertActor($companyId, $actor, $productDispatchBranch);
        $this->assertProductAdjustmentContext($companyId, $documentId, $productDispatchBranch);

        DB::transaction(function () use ($companyId, $actor, $documentId, $data, $ip): void {
            $document = $this->editableDocument($companyId, $documentId);
            $client = $this->activeClient($companyId, (int) $data['cliente_id']);
            $applied = FinancialMoney::subtract(
                (string) $document->total,
                (string) $document->saldo_pendiente,
            );
            if (FinancialMoney::compare($data['importe'], $applied) < 0) {
                throw ValidationException::withMessages([
                    'importe' => "El nuevo total no puede ser menor que lo ya cobrado ({$applied} {$document->moneda}).",
                ]);
            }
            if (FinancialMoney::compare($applied, '0.00') > 0
                && ((int) $document->tercero_id !== (int) $client->id
                    || (string) $document->moneda !== (string) $data['moneda'])) {
                throw ValidationException::withMessages([
                    'deuda' => 'Una deuda con cobros solo permite corregir fecha, importe y detalle. Para cambiar cliente o moneda, anula primero los cobros relacionados.',
                ]);
            }
            $newBalance = FinancialMoney::subtract($data['importe'], $applied);
            $newStatus = match (true) {
                FinancialMoney::compare($newBalance, '0.00') === 0 => 'PAGADO',
                FinancialMoney::compare($applied, '0.00') > 0 => 'PARCIAL',
                default => 'PENDIENTE',
            };
            $before = (array) $document;
            $detailBefore = DB::table('comprobante_detalles')
                ->where('comprobante_id', $documentId)
                ->value('descripcion');
            $now = now();

            DB::table('comprobantes')->where('id', $documentId)->update([
                'tercero_id' => $client->id,
                'fecha_emision' => $data['fecha_emision'],
                'fecha_vencimiento' => $data['fecha_emision'],
                'moneda' => $data['moneda'],
                'subtotal' => $data['importe'],
                'total' => $data['importe'],
                'saldo_pendiente' => $newBalance,
                'estado' => $newStatus,
                'contraparte_tipo_documento_snapshot' => $client->tipo_documento,
                'contraparte_numero_documento_snapshot' => $client->numero_documento,
                'contraparte_nombre_snapshot' => $client->nombre_razon_social,
                'contraparte_direccion_snapshot' => $client->direccion,
                'updated_at' => $now,
            ]);
            DB::table('comprobante_detalles')->where('comprobante_id', $documentId)->update([
                'descripcion' => $data['detalle'],
                'subtotal' => $data['importe'],
            ]);

            $after = (array) DB::table('comprobantes')->where('id', $documentId)->first();
            $before['detalle'] = $detailBefore;
            $after['detalle'] = $data['detalle'];
            $this->audit->record(
                $companyId,
                $actor->id,
                'comprobantes',
                $documentId,
                'EDITAR_DEUDA_ANTERIOR',
                $before,
                $after,
                $ip,
            );
        }, 3);
    }

    public function void(
        int $companyId,
        User $actor,
        int $documentId,
        string $reason,
        ?string $ip = null,
        ?object $productDispatchBranch = null,
    ): void {
        $this->assertActor($companyId, $actor, $productDispatchBranch);
        $this->assertProductAdjustmentContext($companyId, $documentId, $productDispatchBranch);

        DB::transaction(function () use ($companyId, $actor, $documentId, $reason, $ip): void {
            $document = DB::table('comprobantes')
                ->where('empresa_id', $companyId)
                ->where('id', $documentId)
                ->where('origen_clave', 'like', self::ORIGIN_PREFIX.'%')
                ->lockForUpdate()
                ->first();
            abort_unless($document, 404, 'La deuda anterior no fue encontrada.');

            if ($document->estado === 'ANULADO') {
                return;
            }
            $this->assertUnapplied($document);
            $now = now();
            DB::table('comprobantes')->where('id', $documentId)->update([
                'saldo_pendiente' => '0.00',
                'estado' => 'ANULADO',
                'anulada_por' => $actor->id,
                'anulada_at' => $now,
                'motivo_anulacion' => $reason,
                'updated_at' => $now,
            ]);
            $after = (array) $document;
            $after['saldo_pendiente'] = '0.00';
            $after['estado'] = 'ANULADO';
            $after['anulada_por'] = $actor->id;
            $after['anulada_at'] = $now->toDateTimeString();
            $after['motivo_anulacion'] = $reason;
            $this->audit->record(
                $companyId,
                $actor->id,
                'comprobantes',
                $documentId,
                'ANULAR_DEUDA_ANTERIOR',
                (array) $document,
                $after,
                $ip,
            );
        }, 3);
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

    private function editableDocument(int $companyId, int $documentId): object
    {
        $document = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('id', $documentId)
            ->where('origen_clave', 'like', self::ORIGIN_PREFIX.'%')
            ->lockForUpdate()
            ->first();
        abort_unless($document, 404, 'La deuda anterior no fue encontrada.');
        if ($document->estado === 'ANULADO') {
            throw ValidationException::withMessages([
                'deuda' => 'Una deuda anulada no puede editarse.',
            ]);
        }

        return $document;
    }

    private function assertUnapplied(object $document): void
    {
        if ($document->estado !== 'PENDIENTE'
            || FinancialMoney::compare((string) $document->saldo_pendiente, (string) $document->total) !== 0) {
            throw ValidationException::withMessages([
                'deuda' => 'La deuda ya tiene abonos aplicados. Anula primero los movimientos financieros relacionados.',
            ]);
        }
    }

    private function activeClient(int $companyId, int $clientId): object
    {
        $client = DB::table('terceros as cliente')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'cliente.id')
            ->where('cliente.id', $clientId)
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

        return $client;
    }

    private function assertProductAdjustmentContext(int $companyId, int $documentId, ?object $branch): void
    {
        $adjustment = DB::table('ajustes_despacho_productos')->where('empresa_id', $companyId)
            ->where('comprobante_id', $documentId)->first(['sucursal_id']);
        if ($adjustment && ($branch === null || (int) $adjustment->sucursal_id !== (int) $branch->id)) {
            throw ValidationException::withMessages([
                'deuda' => 'Esta deuda pertenece a Despacho de productos. Edítala o elimínala desde Pagos de clientes de su sucursal.',
            ]);
        }
    }

    private function assertActor(int $companyId, User $actor, ?object $productDispatchBranch = null): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId && $actor->isActive(),
            403,
            'Usuario no autorizado para esta empresa.',
        );
        if ($productDispatchBranch !== null) {
            abort_unless((int) $productDispatchBranch->empresa_id === $companyId
                && (! $actor->sucursal_id || (int) $actor->sucursal_id === (int) $productDispatchBranch->id)
                && $actor->hasPermission('PRODUCTOS_DESPACHO_DESPACHAR'), 403);

            return;
        }
        abort_unless(
            $actor->hasPermission('SALDOS_AJUSTAR'),
            403,
            'Se requiere el permiso SALDOS_AJUSTAR.',
        );
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_despacho_productos', function (Blueprint $table): void {
            $table->text('mensaje_ticket_snapshot')
                ->nullable()
                ->after('titulo_ticket_snapshot');
            $table->index(
                ['empresa_id', 'sucursal_id', 'registrado_at', 'id'],
                'ticket_producto_empresa_sucursal_registro_index',
            );
        });

        DB::table('empresas')
            ->select(['id', 'mensaje_ticket'])
            ->orderBy('id')
            ->eachById(function (object $company): void {
                DB::table('tickets_despacho_productos')
                    ->where('empresa_id', $company->id)
                    ->update([
                        'mensaje_ticket_snapshot' => filled($company->mensaje_ticket)
                            ? trim((string) $company->mensaje_ticket)
                            : null,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho_productos', function (Blueprint $table): void {
            $table->dropIndex('ticket_producto_empresa_sucursal_registro_index');
            $table->dropColumn('mensaje_ticket_snapshot');
        });
    }
};

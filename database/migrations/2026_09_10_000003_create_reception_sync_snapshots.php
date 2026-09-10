<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_sync_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('sucursales')->cascadeOnDelete();
            $table->uuid('device_id');
            $table->unsignedInteger('schema_version')->default(1);
            $table->unsignedBigInteger('total_items')->default(0);
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
            $table->index(['company_id', 'branch_id', 'device_id'], 'reception_snapshots_scope_index');
        });

        Schema::create('reception_sync_snapshot_items', function (Blueprint $table): void {
            $table->foreignUuid('snapshot_id')->constrained('reception_sync_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('entity', 40);
            $table->string('entity_key', 191);
            $table->json('data');
            $table->primary(['snapshot_id', 'sequence'], 'reception_snapshot_items_primary');
            $table->unique(['snapshot_id', 'entity', 'entity_key'], 'reception_snapshot_items_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_sync_snapshot_items');
        Schema::dropIfExists('reception_sync_snapshots');
    }
};

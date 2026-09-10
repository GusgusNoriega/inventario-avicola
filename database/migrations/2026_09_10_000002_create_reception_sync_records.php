<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_sync_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('sucursales')->restrictOnDelete();
            $table->uuid('device_id');
            $table->uuid('uuid');
            $table->string('kind', 20);
            $table->date('operating_date');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->json('payload');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'uuid'], 'rpv_sync_record_uuid');
            $table->index(['branch_id', 'operating_date', 'status'], 'rpv_sync_record_date');
        });

        Schema::create('reception_sync_weighing_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('record_id')->constrained('reception_sync_records')->restrictOnDelete();
            $table->uuid('uuid');
            $table->unique(['branch_id', 'uuid'], 'rpv_sync_weighing_uuid');
        });

        Schema::create('reception_sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('sucursales')->restrictOnDelete();
            $table->uuid('device_id');
            $table->uuid('operation_id');
            $table->uuid('entity_id');
            $table->string('request_hash', 64);
            $table->json('request');
            $table->json('result');
            $table->foreignId('actor_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['company_id', 'branch_id', 'device_id', 'operation_id'], 'rpv_sync_operation_uuid');
            $table->index(['branch_id', 'entity_id'], 'rpv_sync_operation_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_sync_operations');
        Schema::dropIfExists('reception_sync_weighing_keys');
        Schema::dropIfExists('reception_sync_records');
    }
};

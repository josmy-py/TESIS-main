<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2.2: registro de los cambios de estado de años lectivos y periodos
 * (activar, cerrar, abrir, reabrir), con la cuenta que los hizo y, en la
 * reapertura de un periodo, el motivo obligatorio. Solo se agregan filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_calendario', function (Blueprint $table) {
            $table->id();
            $table->string('entidad', 20);           // anio_lectivo | periodo_escolar
            $table->unsignedBigInteger('entidad_id');
            $table->string('accion', 20);
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20);
            $table->text('motivo')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entidad', 'entidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_calendario');
    }
};

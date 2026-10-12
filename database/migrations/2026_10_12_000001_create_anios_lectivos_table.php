<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2.2: años lectivos.
 *
 * - estado: planificado, activo o cerrado (reglas en App\Services\CalendarioService).
 * - Las fechas admiten NULL solo para los años creados a partir de datos
 *   anteriores, cuyas fechas reales se desconocen; un año sin fechas válidas
 *   no puede activarse.
 * - activo_unico vale 1 únicamente en el año activo y NULL en los demás: el
 *   índice único garantiza en la base que no haya dos años activos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anios_lectivos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->unique();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->string('estado', 20)->default('planificado');
            $table->boolean('activo_unico')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anios_lectivos');
    }
};

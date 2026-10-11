<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notas_anuales', function (Blueprint $table) {
            $table->id();
            $table->decimal('promedio_anual', 4, 2)->nullable(false);
            $table->date('fecha_cierre')->nullable(false);
            $table->enum('estado',['Aprobada', 'Reprobada']);
            $table->unsignedBigInteger('matricula_id');
            $table->foreign('matricula_id')->references('id')->on('matriculas');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notas_anuales');
    }
};

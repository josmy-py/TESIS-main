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
        Schema::create('conductas', function (Blueprint $table) {
            $table->id();
            $table->string('descripcion');
            $table->string('valoracion');
            $table->unsignedBigInteger('matricula_id');
            $table->foreign('matricula_id')->references('id')->on('matriculas');
            $table->unsignedBigInteger('periodo_escolar_id');
            $table->foreign('periodo_escolar_id')->references('id')->on('periodo_escolares');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conductas');
    }
};

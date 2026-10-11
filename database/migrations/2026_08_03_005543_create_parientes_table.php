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
        Schema::create('parientes', function (Blueprint $table) {
            $table->id();
            $table->enum('parentesco', ['Padre', 'Madre']);
            $table->unsignedBigInteger('padre_id');
            $table->foreign('padre_id')->references('id')->on('padres');
            $table->unsignedBigInteger('estudiante_id');
            $table->foreign('estudiante_id')->references('id')->on('estudiantes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parientes');
    }
};

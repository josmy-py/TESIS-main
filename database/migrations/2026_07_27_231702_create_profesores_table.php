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
        Schema::create('profesores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->nullable(false)->unique();
            $table->date('fecha_nacimiento')->nullable(false);
            $table->string('codigo', 15)->unique()->nullable(false);
            $table->string('dui', 15)->unique()->nullable(false);
            $table->string('telefono', 10)->unique()->nullable(false);
            $table->string('direccion', 100)->nullable(false);
            $table->unsignedBigInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profesores');
    }
};

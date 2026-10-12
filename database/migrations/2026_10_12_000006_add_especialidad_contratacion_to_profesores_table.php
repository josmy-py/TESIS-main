<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3 (D11): datos del docente que pide el informe al MINEDUCYT.
 * Se agregan como opcionales para no romper los perfiles ya registrados;
 * la API los exige al crear o editar un docente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profesores', function (Blueprint $table) {
            $table->string('especialidad', 50)->nullable()->after('direccion');
            $table->date('fecha_contratacion')->nullable()->after('especialidad');
        });
    }

    public function down(): void
    {
        Schema::table('profesores', function (Blueprint $table) {
            $table->dropColumn(['especialidad', 'fecha_contratacion']);
        });
    }
};

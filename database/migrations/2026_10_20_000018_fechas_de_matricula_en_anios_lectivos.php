<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de Josmy (20/10/2026): anios_lectivos.inicio_matricula y
 * fin_matricula, el periodo en que se pueden solicitar matrículas del año.
 * No están en el diagrama ER; se agregan porque Josmy las pidió expresamente.
 * Quedan vacías en los años que ya existen: el director las completa en el
 * formulario del año lectivo (sin ellas no se admiten matrículas nuevas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->date('inicio_matricula')->nullable()->after('fecha_fin');
            $table->date('fin_matricula')->nullable()->after('inicio_matricula');
        });
    }

    public function down(): void
    {
        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->dropColumn(['inicio_matricula', 'fin_matricula']);
        });
    }
};

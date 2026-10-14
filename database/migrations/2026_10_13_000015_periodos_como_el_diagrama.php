<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solo el diagrama (13/10/2026): periodo_escolares sin estado (abrir, cerrar y
 * reabrir periodos no están en el diagrama). El estado de cada periodo queda en
 * storage/app/respaldos/; revertir lo devuelve.
 */
return new class extends Migration
{
    private const RESPALDO = 'periodos';

    public function up(): void
    {
        RespaldoAlineacion::guardar(self::RESPALDO, [
            'periodo_escolares' => RespaldoAlineacion::filas(DB::table('periodo_escolares'), ['id', 'estado']),
        ]);

        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }

    public function down(): void
    {
        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->string('estado', 20)->default('pendiente')->after('anio_lectivo_id');
        });

        foreach (RespaldoAlineacion::leer(self::RESPALDO)['periodo_escolares'] ?? [] as $fila) {
            DB::table('periodo_escolares')->where('id', $fila['id'])->update(['estado' => $fila['estado']]);
        }
        RespaldoAlineacion::archivar(self::RESPALDO);
    }
};

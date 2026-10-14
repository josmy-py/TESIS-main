<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solo el diagrama (13/10/2026): plan_estudios sin grado_id.
 * El grado de cada plan queda en storage/app/respaldos/; revertir lo devuelve.
 */
return new class extends Migration
{
    // SQLite no permite suspender las claves foráneas dentro de una transacción
    public $withinTransaction = false;

    private const RESPALDO = 'plan-estudios';

    public function up(): void
    {
        RespaldoAlineacion::guardar(self::RESPALDO, [
            'plan_estudios' => RespaldoAlineacion::filas(DB::table('plan_estudios'), ['id', 'grado_id']),
        ]);

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->dropForeign(['grado_id']);
            });
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->dropColumn('grado_id');
            });
        });
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->foreignId('grado_id')->nullable()->after('nombre')->constrained('grados');
            });
        });

        foreach (RespaldoAlineacion::leer(self::RESPALDO)['plan_estudios'] ?? [] as $fila) {
            DB::table('plan_estudios')->where('id', $fila['id'])->update(['grado_id' => $fila['grado_id']]);
        }
        RespaldoAlineacion::archivar(self::RESPALDO);
    }
};

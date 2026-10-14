<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solo el diagrama (13/10/2026): profesores sin especialidad ni fecha_contratacion.
 * Los valores quitados quedan en storage/app/respaldos/; revertir los devuelve.
 */
return new class extends Migration
{
    private const RESPALDO = 'profesores';

    public function up(): void
    {
        RespaldoAlineacion::guardar(self::RESPALDO, [
            'profesores' => RespaldoAlineacion::filas(DB::table('profesores'), ['id', 'especialidad', 'fecha_contratacion']),
        ]);

        Schema::table('profesores', function (Blueprint $table) {
            $table->dropColumn(['especialidad', 'fecha_contratacion']);
        });
    }

    public function down(): void
    {
        Schema::table('profesores', function (Blueprint $table) {
            $table->string('especialidad', 50)->nullable()->after('direccion');
            $table->date('fecha_contratacion')->nullable()->after('especialidad');
        });

        foreach (RespaldoAlineacion::leer(self::RESPALDO)['profesores'] ?? [] as $fila) {
            DB::table('profesores')->where('id', $fila['id'])->update(collect($fila)->except('id')->all());
        }
        RespaldoAlineacion::archivar(self::RESPALDO);
    }
};

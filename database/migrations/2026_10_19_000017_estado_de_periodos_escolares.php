<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de Josmy (19/10/2026): periodo_escolares.estado para cerrar cada
 * trimestre o periodo. No está en el diagrama ER; se agrega porque Josmy lo
 * pidió expresamente y la documentación lo usa (pág. 61 "Trimestre 1 En
 * curso", pág. 69 la boleta depende de que el periodo esté "Cerrado").
 * Estados: pendiente (por defecto), en_curso y cerrado. Cada periodo recupera el
 * estado que guardó la migración 000015 al quitar la columna ("abierto" pasa a
 * en_curso); los de un año ya cerrado quedan cerrados; los demás, pendientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->enum('estado', ['pendiente', 'en_curso', 'cerrado'])->default('pendiente')->after('nivel');
        });

        foreach (RespaldoAlineacion::leer('periodos')['periodo_escolares'] ?? [] as $fila) {
            $estado = ['abierto' => 'en_curso', 'en_curso' => 'en_curso', 'cerrado' => 'cerrado'][$fila['estado'] ?? ''] ?? null;
            if ($estado) {
                DB::table('periodo_escolares')->where('id', $fila['id'])->update(['estado' => $estado]);
            }
        }

        DB::table('periodo_escolares')
            ->whereIn('anio_lectivo_id', DB::table('anios_lectivos')->where('estado', 'cierre')->select('id'))
            ->update(['estado' => 'cerrado']);
    }

    public function down(): void
    {
        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};

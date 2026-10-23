<?php

use App\Support\CatalogoGrados;
use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pedido de Josmy (23/10/2026): periodo_escolares.nivel es el nivel académico
 * al que pertenece el periodo (tesis, diccionario de datos: "formato de nivel
 * escolar"), no su número: 1 = Parvularia y Educación Básica, 2 = Bachillerato.
 * No cambia la estructura de la tabla, solo los datos:
 * - Todo periodo existente de un año lectivo pasa a nivel 1.
 * - Si el año lectivo tiene secciones de bachillerato, cada periodo se copia
 *   con nivel 2 (mismo nombre y estado) y las evaluaciones, boletas y
 *   conductas de bachillerato pasan a la copia. No se pierde ningún registro.
 * El respaldo (storage/app/respaldos/) guarda el nivel anterior y lo que se
 * movió; revertir la migración lo devuelve todo como estaba.
 */
return new class extends Migration
{
    private const RESPALDO = 'nivel-academico-periodos';

    public function up(): void
    {
        // Los periodos anteriores a M2.2 sin año lectivo no se usan: se dejan como están
        $periodos = DB::table('periodo_escolares')->whereNotNull('anio_lectivo_id')->orderBy('id')->get(['id', 'nombre', 'nivel', 'anio_lectivo_id', 'estado']);
        $niveles = $periodos->map(fn ($p) => ['id' => $p->id, 'nivel' => $p->nivel])->all();

        DB::table('periodo_escolares')->whereNotNull('anio_lectivo_id')->update(['nivel' => 1]);

        // Secciones de bachillerato (orden 10 en adelante del catálogo) por año lectivo
        $bachillerato = DB::table('secciones')->join('grados', 'grados.id', '=', 'secciones.grado_id')
            ->get(['secciones.id', 'secciones.anio_lectivo_id', 'grados.nombre'])
            ->filter(fn ($s) => (CatalogoGrados::orden($s->nombre) ?? 0) >= 10)
            ->groupBy('anio_lectivo_id');

        $copias = [];
        foreach ($periodos->groupBy('anio_lectivo_id') as $anioId => $delAnio) {
            $secciones = ($bachillerato[$anioId] ?? collect())->pluck('id');
            if ($secciones->isEmpty()) {
                continue;
            }
            $asignaciones = DB::table('asignaciones')->whereIn('seccion_id', $secciones)->pluck('id');
            $matriculas = DB::table('matriculas')->whereIn('seccion_id', $secciones)->pluck('id');

            foreach ($delAnio as $periodo) {
                $copia = DB::table('periodo_escolares')->insertGetId([
                    'nombre' => $periodo->nombre,
                    'nivel' => 2,
                    'anio_lectivo_id' => $anioId,
                    'estado' => $periodo->estado,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $movidos = [
                    'evaluaciones' => DB::table('evaluaciones')->where('periodo_escolar_id', $periodo->id)->whereIn('asignacion_id', $asignaciones)->pluck('id')->all(),
                    'boletas' => DB::table('boletas')->where('periodo_escolar_id', $periodo->id)->whereIn('matricula_id', $matriculas)->pluck('id')->all(),
                    'conductas' => DB::table('conductas')->where('periodo_escolar_id', $periodo->id)->whereIn('matricula_id', $matriculas)->pluck('id')->all(),
                ];
                foreach ($movidos as $tabla => $ids) {
                    DB::table($tabla)->whereIn('id', $ids)->update(['periodo_escolar_id' => $copia]);
                }
                $copias[] = ['original' => $periodo->id, 'copia' => $copia] + $movidos;
            }
        }

        RespaldoAlineacion::guardar(self::RESPALDO, ['niveles' => $niveles, 'copias' => $copias]);
    }

    public function down(): void
    {
        $respaldo = RespaldoAlineacion::leer(self::RESPALDO);
        if ($respaldo === null) {
            // Sin respaldo (base vacía al aplicar o respaldo borrado): se vuelve al
            // significado anterior numerando los periodos de cada año por su id
            $porAnio = DB::table('periodo_escolares')->whereNotNull('anio_lectivo_id')->orderBy('id')->get(['id', 'anio_lectivo_id'])->groupBy('anio_lectivo_id');
            foreach ($porAnio as $periodos) {
                foreach ($periodos->values() as $i => $periodo) {
                    DB::table('periodo_escolares')->where('id', $periodo->id)->update(['nivel' => $i + 1]);
                }
            }

            return;
        }

        foreach ($respaldo['copias'] ?? [] as $copia) {
            foreach (['evaluaciones', 'boletas', 'conductas'] as $tabla) {
                DB::table($tabla)->where('periodo_escolar_id', $copia['copia'])->update(['periodo_escolar_id' => $copia['original']]);
            }
            DB::table('periodo_escolares')->where('id', $copia['copia'])->delete();
        }
        foreach ($respaldo['niveles'] ?? [] as $fila) {
            DB::table('periodo_escolares')->where('id', $fila['id'])->update(['nivel' => $fila['nivel']]);
        }

        RespaldoAlineacion::archivar(self::RESPALDO);
    }
};

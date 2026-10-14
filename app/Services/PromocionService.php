<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Estudiante;
use App\Models\Grado;
use App\Models\Matricula;
use App\Models\PlanEstudio;
use App\Models\Seccion;
use App\Support\CatalogoGrados;

/**
 * M6.1: qué puede matricular un estudiante en un año lectivo (D-M5) y con qué
 * plan de estudio (D-M3).
 *
 * Promoción (tesis pág. 57 y decisiones de escalabilidad del 13/10/2026):
 * - Sin matrículas aprobadas en SIGA: Nuevo ingreso, cualquier grado del catálogo.
 * - Con historial en SIGA: siempre Reingreso (no se puede pasar por nuevo
 *   ingreso para evitar la validación). Aprobó: el grado correlativo siguiente
 *   con la misma especialidad; reprobó: repite el grado.
 * - Nunca se salta un grado: si el siguiente no está registrado, se bloquea
 *   con un aviso.
 * - Noveno grado pasa a Primer año de bachillerato eligiendo especialidad.
 *   Bachillerato General termina en Segundo año; el tercer año solo existirá
 *   para modalidades técnicas (hoy no está en el catálogo).
 * - La nota anual la calcula la libreta (M9, LibretaService): un reingreso sin nota anual queda bloqueado.
 */
class PromocionService
{
    public const NOTA_MINIMA = 7.0;

    /**
     * @return array{ingreso: ?string, grados: array<int>, especialidad_id: int|null|false, mensaje: string, bloqueo: ?string}
     *   especialidad_id false = cualquiera (la define el plan de la sección).
     */
    public function opciones(Estudiante $estudiante, ?AnioLectivo $anio): array
    {
        $bloqueo = fn (string $mensaje) => ['ingreso' => null, 'grados' => [], 'especialidad_id' => false, 'mensaje' => $mensaje, 'bloqueo' => $mensaje];

        if ($estudiante->estado === 'graduado') {
            return $bloqueo("{$estudiante->nombre} ya se graduó: no admite más matrículas.");
        }

        $anterior = $this->ultimaAprobada($estudiante, $anio);

        if (! $anterior) {
            return [
                'ingreso' => 'Nuevo ingreso',
                'grados' => Grado::ordenAcademico()->filter(fn (Grado $g) => $g->orden !== null)->pluck('id')->all(),
                'especialidad_id' => false,
                'mensaje' => 'No tiene matrículas aprobadas en SIGA: corresponde Nuevo ingreso.',
                'bloqueo' => null,
            ];
        }

        $grado = $anterior->seccion?->grado;
        $anioAnterior = $anterior->anioLectivo?->nombre ?? '(año sin registrar)';
        if (! $grado || $grado->orden === null) {
            return $bloqueo("No se puede determinar el grado de la matrícula aprobada de {$anioAnterior}: no tiene sección o su grado no está en el catálogo oficial.");
        }

        $nota = $anterior->notaFinal()->latest('id')->first();
        if (! $nota) {
            return $bloqueo("La matrícula de {$anioAnterior} ({$grado->nombre}) aún no tiene nota anual registrada; sin ella no se puede validar la promoción.");
        }

        $aprobo = $nota->estado === 'Aprobada' && (float) $nota->promedio_anual >= self::NOTA_MINIMA;
        $especialidad = self::id($anterior->especialidad_id);

        if (! $aprobo) {
            return [
                'ingreso' => 'Reingreso', 'grados' => [$grado->id], 'especialidad_id' => $especialidad,
                'mensaje' => "No aprobó {$grado->nombre} en {$anioAnterior}: debe repetir el grado.", 'bloqueo' => null, 'repite' => true,
            ];
        }

        $orden = $grado->orden + 1;
        $nombre = array_search($orden, CatalogoGrados::GRADOS, true);
        if ($nombre === false) {
            return $bloqueo("{$estudiante->nombre} aprobó {$grado->nombre}, el último grado que ofrece la institución.");
        }

        $siguiente = Grado::ordenAcademico()->first(fn (Grado $g) => $g->orden === $orden);
        if (! $siguiente) {
            return $bloqueo("El grado siguiente ({$nombre}) no está registrado en SIGA. No se puede saltar grados: regístrelo antes de matricular.");
        }

        // Noveno grado -> Primer año de bachillerato: elige especialidad
        $primerBachillerato = CatalogoGrados::GRADOS['Primer año de bachillerato'];

        return [
            'ingreso' => 'Reingreso',
            'grados' => [$siguiente->id],
            'especialidad_id' => $orden === $primerBachillerato ? false : $especialidad,
            'mensaje' => "Aprobó {$grado->nombre} en {$anioAnterior}: corresponde {$siguiente->nombre}.",
            'bloqueo' => null,
        ];
    }

    /**
     * Última matrícula aprobada anterior al año indicado (sin año: la última de
     * todas). Las aprobadas de datos anteriores sin año lectivo también cuentan
     * como historial, para que nadie pase por Nuevo ingreso teniéndolo.
     */
    public function ultimaAprobada(Estudiante $estudiante, ?AnioLectivo $anio): ?Matricula
    {
        return Matricula::with(['seccion.grado', 'anioLectivo'])
            ->where('estudiante_id', $estudiante->id)
            ->where('estado', 'aprobado')
            ->when($anio, fn ($q) => $q->where(fn ($w) => $w->whereNull('anio_lectivo_id')->orWhere(
                fn ($o) => $o->where('anio_lectivo_id', '<>', $anio->id)
                    ->whereHas('anioLectivo', fn ($a) => $a->where('fecha_inicio', '<', $anio->fecha_inicio))
            )))
            ->get()
            ->sortByDesc(fn (Matricula $m) => [(string) $m->anioLectivo?->fecha_inicio, $m->id])
            ->first();
    }

    /**
     * D-M3: plan efectivo de la sección, el de sus materias asignadas (M4).
     * Si la sección aún no tiene materias no hay plan y vale la especialidad
     * que eligió el encargado en la matrícula.
     * $especialidad: id, null (sin especialidad) o false (no indicada).
     *
     * @return array{plan: ?PlanEstudio, error: ?string, campo: ?string} campo: el dato del formulario que falla
     */
    public function planEfectivo(Seccion $seccion, int|false|null $especialidad): array
    {
        $seccion->loadMissing('grado');
        $etiqueta = "{$seccion->grado?->nombre} {$seccion->nombre}";
        $plan = app(AsignacionService::class)->planDeSeccion($seccion->id);

        if ($plan) {
            if ($especialidad !== false && self::id($plan->especialidad_id) !== $especialidad) {
                return ['plan' => null, 'error' => "La especialidad no coincide con el plan de la sección {$etiqueta} ({$plan->nombre}).", 'campo' => 'especialidad_id'];
            }

            return ['plan' => $plan, 'error' => null, 'campo' => null];
        }

        return ['plan' => null, 'error' => null, 'campo' => null];
    }

    public static function id($valor): ?int
    {
        return $valor === null || $valor === '' ? null : (int) $valor;
    }
}

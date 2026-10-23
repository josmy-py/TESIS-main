<?php

namespace Tests\Feature\M8;

use App\Models\AnioLectivo;
use App\Models\Asignacion;
use App\Models\Asignatura;
use App\Models\Calificacion;
use App\Models\Evaluacion;
use App\Models\Grado;
use App\Models\PeriodoEscolar;
use App\Models\PlanEstudio;
use App\Models\Profesor;
use App\Models\Seccion;
use App\Models\User;
use Tests\Feature\M1\DatosM1;

/**
 * Escenario para M8 a M12: un año activo con dos periodos, una sección con dos
 * materias (cada una con su docente), dos estudiantes aprobados y uno pendiente,
 * todos de la misma cuenta de encargado.
 */
trait DatosAcademicos
{
    use DatosM1;

    protected AnioLectivo $anio;
    protected array $periodos = [];
    protected User $cuentaDocente;
    protected User $cuentaOtroDocente;
    protected User $encargado;
    protected Seccion $seccion;
    protected Asignacion $matematica;
    protected Asignacion $lenguaje;
    protected array $matriculas = [];
    protected array $estudiantes = [];

    protected function prepararSeccion(): void
    {
        $this->sembrarRoles();
        $catalogos = $this->catalogosBasicos();
        $this->anio = AnioLectivo::create(['anio' => '2026', 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30']);
        $this->anio->forceFill(['estado' => AnioLectivo::ACTIVO])->save();
        foreach (['Primer trimestre', 'Segundo trimestre'] as $i => $nombre) {
            $this->periodos[] = PeriodoEscolar::create(['nombre' => $nombre, 'nivel' => 1, 'anio_lectivo_id' => $this->anio->id]);
        }

        [$this->cuentaDocente, $docente] = $this->docenteNuevo(1);
        [$this->cuentaOtroDocente, $otro] = $this->docenteNuevo(2);

        $grado = Grado::create(['nombre' => 'Primer grado']);
        $this->seccion = Seccion::create([
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => $grado->id, 'profesor_id' => $docente->id,
        ]);
        $plan = PlanEstudio::create([
            'nombre' => 'Plan', 'especialidad_id' => null, 'estado' => true,
            'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2030-11-30', 'fecha_aprobacion' => '2025-12-01', 'descripcion' => 'Pensum',
        ]);
        $this->matematica = Asignacion::create(['profesor_id' => $docente->id, 'seccion_id' => $this->seccion->id,
            'asignatura_id' => Asignatura::create(['nombre' => 'Matemática', 'plan_estudio_id' => $plan->id])->id]);
        $this->lenguaje = Asignacion::create(['profesor_id' => $otro->id, 'seccion_id' => $this->seccion->id,
            'asignatura_id' => Asignatura::create(['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan->id])->id]);

        $this->encargado = $this->usuario(User::ROL_ENCARGADO);
        foreach (['Zoila Pérez' => 'aprobado', 'Ana López' => 'aprobado', 'Pedro Pendiente' => 'pendiente'] as $nombre => $estado) {
            $this->estudiantes[$nombre] = $this->estudianteDe($this->encargado, $catalogos['distrito'], ['nombre' => $nombre]);
            $this->matriculas[$nombre] = $this->matriculaDe($this->estudiantes[$nombre], $catalogos, [
                'estado' => $estado, 'seccion_id' => $this->seccion->id, 'anio_lectivo_id' => $this->anio->id,
            ]);
        }
    }

    protected function docenteNuevo(int $n): array
    {
        $user = $this->usuario(User::ROL_DOCENTE);

        return [$user, Profesor::create([
            'nombre' => "Docente {$n}", 'dui' => sprintf('4%07d-0', $n), 'fecha_nacimiento' => '1990-01-01',
            'codigo' => sprintf('PROF-8%02d', $n), 'telefono' => sprintf('7300-%04d', $n), 'direccion' => 'X', 'user_id' => $user->id,
        ])];
    }

    /**
     * Evaluaciones y notas directas en la base: [[porcentaje, ['Ana López' => nota, ...]], ...].
     */
    protected function conNotas(Asignacion $asignacion, PeriodoEscolar $periodo, array $evaluaciones): void
    {
        foreach ($evaluaciones as $i => [$porcentaje, $notas]) {
            $evaluacion = Evaluacion::create(['nombre' => 'Evaluación '.($i + 1), 'porcentaje' => $porcentaje,
                'periodo_escolar_id' => $periodo->id, 'asignacion_id' => $asignacion->id]);
            foreach ($notas as $nombre => $nota) {
                Calificacion::create(['nota_obtenida' => $nota, 'evaluacion_id' => $evaluacion->id, 'matricula_id' => $this->matriculas[$nombre]]);
            }
        }
    }
}

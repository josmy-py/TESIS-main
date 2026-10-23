<?php

namespace Tests\Feature\Reportes;

use App\Models\AnioLectivo;
use App\Models\Boleta;
use App\Models\Grado;
use App\Models\User;
use App\Services\Profesor\ReportesProfesorPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte de Rendimiento (portal docente): una materia propia del docente, las
 * boletas del período de los alumnos aprobados y el PDF con dompdf.
 */
class RendimientoTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private const URL = '/api/docente/reportes/rendimiento/pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->travelTo(now()->parse('2026-06-12 15:00:00', 'UTC'));
        $periodo = $this->periodos[0]->id;
        // Ana 9.5 destacada, Zoila 7; Pedro (pendiente) no cuenta
        Boleta::create(['promedio' => 9.5, 'matricula_id' => $this->matriculas['Ana López'], 'asignacion_id' => $this->matematica->id, 'periodo_escolar_id' => $periodo]);
        Boleta::create(['promedio' => 7, 'matricula_id' => $this->matriculas['Zoila Pérez'], 'asignacion_id' => $this->matematica->id, 'periodo_escolar_id' => $periodo]);
        Boleta::create(['promedio' => 10, 'matricula_id' => $this->matriculas['Pedro Pendiente'], 'asignacion_id' => $this->matematica->id, 'periodo_escolar_id' => $periodo]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'grado_id' => $this->seccion->grado_id, 'seccion_id' => $this->seccion->id, 'periodo_escolar_id' => $this->periodos[0]->id,
            'asignacion_id' => $this->matematica->id, 'profesor' => 'Prof. González', 'observaciones' => 'Grupo satisfactorio.',
        ], $cambios);
    }

    public function test_el_docente_genera_el_reporte_de_su_materia(): void
    {
        $r = $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos())->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $datos = app(ReportesProfesorPdfService::class)->datosRendimiento($this->cuentaDocente, $this->datos());
        $this->assertSame(2, $datos['total_estudiantes']);
        $this->assertEquals(8.25, $datos['promedio_general']);

        $html = view('profesor.rendimiento', $datos)->render();
        foreach (['COMPLEJO EDUCATIVO HACIENDA COLIMA', 'REPORTE DE RENDIMIENTO ACADÉMICO', 'Primer grado', 'Prof. González', 'Matemática',
            'Primer trimestre', '<b>Fecha:</b> 12/06/2026', '<b>Año lectivo:</b> 2026', '<b>Total de estudiantes:</b> 2', '<b>Promedio general:</b> 8.25', 'PROMEDIO ≥ 9.0',
            '<td>Ana López</td><td class="prom">9.5</td>', 'Grupo satisfactorio.', 'Director(a)'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertStringNotContainsString('Zoila', $html);

        // Otro período sin boletas
        $html = view('profesor.rendimiento', app(ReportesProfesorPdfService::class)
            ->datosRendimiento($this->cuentaDocente, $this->datos(['periodo_escolar_id' => $this->periodos[1]->id])))->render();
        $this->assertStringContainsString('<b>Promedio general:</b> —', $html);
        $this->assertStringContainsString('Ningún estudiante alcanza el promedio.', $html);
    }

    public function test_solo_materias_propias_y_datos_coherentes(): void
    {
        // Lenguaje es del otro docente, aunque el primero sea guía
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['asignacion_id' => $this->lenguaje->id]))
            ->assertStatus(422)->assertJsonValidationErrors('asignacion_id');
        $this->como($this->cuentaOtroDocente)->postJson(self::URL, $this->datos(['asignacion_id' => $this->lenguaje->id]))->assertOk();

        $otroGrado = Grado::create(['nombre' => 'Segundo grado']);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['grado_id' => $otroGrado->id]))
            ->assertStatus(422)->assertJsonValidationErrors('seccion_id');
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['periodo_escolar_id' => 999]))
            ->assertStatus(422)->assertJsonValidationErrors('periodo_escolar_id');
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['profesor' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('profesor');

        // Solo secciones del año lectivo activo
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos())
            ->assertStatus(422)->assertJsonValidationErrors(['seccion_id' => 'año lectivo activo']);
    }

    public function test_solo_docentes(): void
    {
        $this->como($this->usuario(User::ROL_DIRECTOR))->postJson(self::URL, $this->datos())->assertForbidden();
        $this->como($this->encargado)->postJson(self::URL, $this->datos())->assertForbidden();
    }
}

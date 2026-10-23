<?php

namespace Tests\Feature\Reportes;

use App\Models\Boleta;
use App\Models\AnioLectivo;
use App\Models\Grado;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Profesor\ReportesProfesorPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Libreta de notas de un estudiante (portal docente): materias y promedios de
 * las boletas del período (el guía ve todas las materias, los demás docentes solo
 * las suyas) y PDF con dompdf. Solo con el período cerrado y una sección del año
 * lectivo activo (pedido de Josmy del 23/10/2026).
 */
class LibretaNotasTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private const URL = '/api/docente/reportes/libreta-notas/pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->travelTo(now()->parse('2026-06-12 15:00:00', 'UTC'));
        $periodo = $this->periodos[0]->id;
        $this->periodos[0]->forceFill(['estado' => PeriodoEscolar::CERRADO])->save();
        Boleta::create(['promedio' => 8.3, 'matricula_id' => $this->matriculas['Ana López'], 'asignacion_id' => $this->matematica->id, 'periodo_escolar_id' => $periodo]);
        Boleta::create(['promedio' => 9, 'matricula_id' => $this->matriculas['Ana López'], 'asignacion_id' => $this->lenguaje->id, 'periodo_escolar_id' => $periodo]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'grado_id' => $this->seccion->grado_id, 'seccion_id' => $this->seccion->id,
            'periodo_escolar_id' => $this->periodos[0]->id, 'matricula_id' => $this->matriculas['Ana López'],
            'conducta' => 'Excelente', 'observaciones' => '',
        ], $cambios);
    }

    private function html(User $docente, array $cambios = []): string
    {
        return view('profesor.boleta', app(ReportesProfesorPdfService::class)->datosLibreta($docente, $this->datos($cambios)))->render();
    }

    public function test_el_guia_genera_la_libreta_con_todas_las_materias(): void
    {
        $r = $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos())->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $html = $this->html($this->cuentaDocente);
        foreach (['COMPLEJO EDUCATIVO HACIENDA COLIMA', 'REPORTE DE LIBRETA DE NOTAS', '12/06/2026', 'Primer trimestre', 'Primer grado',
            'Docente 1', 'Año lectivo:</b> <span class="valor">2026</span>', 'Ana López', 'Excelente', 'DETALLE DE CALIFICACIONES',
            '<td>Matemática</td><td class="nota">8.3</td>', '<td>Lenguaje</td><td class="nota">9.0</td>',
            '<td>Promedio general</td><td class="nota">8.65</td>', 'Sello Institucional'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertStringNotContainsString('OBSERVACIONES DEL DOCENTE', $html);
        $this->assertStringContainsString('OBSERVACIONES DEL DOCENTE', $this->html($this->cuentaDocente, ['observaciones' => 'Buen trimestre.']));
    }

    public function test_el_docente_que_no_es_guia_solo_ve_sus_materias(): void
    {
        $html = $this->html($this->cuentaOtroDocente);
        $this->assertStringContainsString('<td>Lenguaje</td><td class="nota">9.0</td>', $html);
        $this->assertStringNotContainsString('Matemática', $html);
        $this->assertStringContainsString('<td>Promedio general</td><td class="nota">9.0</td>', $html);

        // Sin boleta aún: guion
        $html = $this->html($this->cuentaDocente, ['matricula_id' => $this->matriculas['Zoila Pérez']]);
        $this->assertStringContainsString('<td>Matemática</td><td class="nota">—</td>', $html);
    }

    public function test_valida_grado_seccion_periodo_y_estudiante(): void
    {
        $otroGrado = Grado::create(['nombre' => 'Segundo grado']);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['grado_id' => $otroGrado->id]))
            ->assertStatus(422)->assertJsonValidationErrors('seccion_id');

        [, $tercero] = $this->docenteNuevo(3);
        $ajena = Seccion::create(['nombre' => 'B', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => $this->seccion->grado_id, 'profesor_id' => $tercero->id]);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['seccion_id' => $ajena->id]))->assertNotFound();

        // Matrícula pendiente (no aprobada) o inexistente
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['matricula_id' => $this->matriculas['Pedro Pendiente']]))
            ->assertStatus(422)->assertJsonValidationErrors('matricula_id');
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['matricula_id' => 999]))
            ->assertStatus(422)->assertJsonValidationErrors('matricula_id');

        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['periodo_escolar_id' => 999]))
            ->assertStatus(422)->assertJsonValidationErrors('periodo_escolar_id');
    }

    public function test_solo_con_el_periodo_cerrado(): void
    {
        foreach ([PeriodoEscolar::PENDIENTE, PeriodoEscolar::ACTIVO] as $estado) {
            $this->periodos[0]->forceFill(['estado' => $estado])->save();
            $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos())
                ->assertStatus(422)->assertJsonValidationErrors(['periodo_escolar_id' => 'solo se genera cuando el período está cerrado']);
        }
    }

    public function test_solo_secciones_del_anio_activo(): void
    {
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos())
            ->assertStatus(422)->assertJsonValidationErrors(['seccion_id' => 'año lectivo activo']);
        $this->como($this->cuentaDocente)->getJson('/api/docente/reportes/notas?seccion_id='.$this->seccion->id.'&periodo_escolar_id='.$this->periodos[0]->id)
            ->assertStatus(422)->assertJsonValidationErrors('seccion_id');
    }

    public function test_solo_docentes(): void
    {
        $this->como($this->usuario(User::ROL_DIRECTOR))->postJson(self::URL, $this->datos())->assertForbidden();
        $this->como($this->encargado)->postJson(self::URL, $this->datos())->assertForbidden();
    }
}

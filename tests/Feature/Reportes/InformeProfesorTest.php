<?php

namespace Tests\Feature\Reportes;

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
 * Informe de Profesor del portal docente: grado, sección y período salen de la
 * base (sección donde el docente enseña o es guía) y el PDF se genera con dompdf.
 */
class InformeProfesorTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private const URL = '/api/docente/reportes/informe-profesor/pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        // La fecha del PDF es la del día en El Salvador (UTC-6): 9 de junio 03:00 UTC = 8 de junio
        $this->travelTo(now()->parse('2026-06-09 03:00:00', 'UTC'));
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'grado_id' => $this->seccion->grado_id, 'seccion_id' => $this->seccion->id,
            'periodo_escolar_id' => $this->periodos[0]->id, 'profesor' => 'Prof. Gonzales',
            'asunto' => 'Rendimiento del grupo en el primer trimestre.',
            'desarrollo' => "El grupo mostró avances.\nSe reforzó la lectura.", 'observaciones' => '',
        ], $cambios);
    }

    public function test_el_docente_genera_el_pdf_con_los_datos_del_sistema(): void
    {
        $r = $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos())->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        // También el docente que solo da una materia en la sección
        $this->como($this->cuentaOtroDocente)->postJson(self::URL, $this->datos())->assertOk();

        $html = view('profesor.informe_profesor', app(ReportesProfesorPdfService::class)->datosInforme($this->cuentaDocente, $this->datos()))->render();
        foreach (['COMPLEJO EDUCATIVO HACIENDA COLIMA', 'INFORME DE PROFESOR', 'Primer grado', 'Primer trimestre', 'Prof. Gonzales',
            '08/06/2026', 'ASUNTO', 'Rendimiento del grupo en el primer trimestre.', 'DESARROLLO DEL INFORME',
            'El grupo mostró avances.<br />', 'OBSERVACIONES'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
    }

    public function test_valida_grado_seccion_y_periodo(): void
    {
        $otroGrado = Grado::create(['nombre' => 'Segundo grado']);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['grado_id' => $otroGrado->id]))
            ->assertStatus(422)->assertJsonValidationErrors('seccion_id');

        // Sección donde el docente no enseña ni es guía
        [, $tercero] = $this->docenteNuevo(3);
        $ajena = Seccion::create(['nombre' => 'B', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => $this->seccion->grado_id, 'profesor_id' => $tercero->id]);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['seccion_id' => $ajena->id]))->assertNotFound();

        // Período de otro año lectivo
        $anio = AnioLectivo::create(['anio' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $periodo = PeriodoEscolar::create(['nombre' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $anio->id]);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['periodo_escolar_id' => $periodo->id]))
            ->assertStatus(422)->assertJsonValidationErrors('periodo_escolar_id');

        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['asunto' => '', 'profesor' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['asunto', 'profesor']);
    }

    public function test_solo_docentes(): void
    {
        $this->como($this->usuario(User::ROL_DIRECTOR))->postJson(self::URL, $this->datos())->assertForbidden();
        $this->como($this->encargado)->postJson(self::URL, $this->datos())->assertForbidden();
    }
}

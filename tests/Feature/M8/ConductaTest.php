<?php

namespace Tests\Feature\M8;

use App\Models\AnioLectivo;
use App\Models\Conducta;
use App\Models\PeriodoEscolar;
use App\Models\User;
use App\Services\ConductaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M8 Conducta (diagrama: Conducta.registrar() y actualizar(descripcion, valoracion);
 * Docente > Conducta > Registrar observaciones / Seguimiento conductual).
 */
class ConductaTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
    }

    private function guardar(array $conductas, ?User $docente = null, $periodo = null)
    {
        return $this->como($docente ?? $this->cuentaDocente)->putJson("/api/docente/secciones/{$this->seccion->id}/conducta", [
            'periodo_escolar_id' => ($periodo ?? $this->periodos[0])->id, 'conductas' => $conductas,
        ]);
    }

    public function test_lista_estudiantes_aprobados_con_su_conducta(): void
    {
        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta")->assertOk();

        $this->assertSame(['Ana López', 'Zoila Pérez'], collect($r->json('estudiantes'))->pluck('estudiante.nombre')->all());
        $this->assertSame($this->periodos[0]->id, $r->json('periodo.id'));
        $this->assertTrue($r->json('editable'));
        $this->assertNull($r->json('estudiantes.0.conducta'));

        // Con el primer periodo cerrado, el predeterminado tampoco es editable
        $this->periodos[0]->forceFill(['estado' => PeriodoEscolar::CERRADO])->save();
        $this->como($this->cuentaDocente)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta")
            ->assertOk()->assertJsonPath('periodo.id', $this->periodos[0]->id)->assertJsonPath('editable', false);
    }

    public function test_registrar_y_actualizar(): void
    {
        $ana = $this->matriculas['Ana López'];
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Excelente']])
            ->assertOk()->assertJson(['registradas' => 1, 'actualizadas' => 0]);
        // El docente de otra materia de la sección también da seguimiento
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Bueno']], $this->cuentaOtroDocente)
            ->assertOk()->assertJson(['registradas' => 0, 'actualizadas' => 1]);
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Bueno']])
            ->assertOk()->assertJson(['registradas' => 0, 'actualizadas' => 0]);
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Excelente']], null, $this->periodos[1])
            ->assertOk()->assertJson(['registradas' => 1]);

        $this->assertSame(2, Conducta::count());
        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta?periodo_escolar_id={$this->periodos[0]->id}");
        $this->assertSame(['descripcion' => ConductaService::OBSERVACIONES['Bueno'], 'valoracion' => 'Bueno'], collect($r->json('estudiantes.0.conducta'))->except('id')->all());
        $this->assertSame(ConductaService::OBSERVACIONES, $r->json('observaciones'));
    }

    public function test_la_observacion_es_fija_segun_la_valoracion(): void
    {
        $ana = $this->matriculas['Ana López'];
        // Lo que envíe el cliente como observación se ignora
        $this->guardar([['matricula_id' => $ana, 'descripcion' => 'Texto libre', 'valoracion' => 'Malo']])->assertOk();
        $this->assertSame(ConductaService::OBSERVACIONES['Malo'], Conducta::first()->descripcion);

        // Un registro anterior con texto libre se conserva hasta que se vuelve a guardar
        Conducta::first()->update(['descripcion' => 'Registro anterior']);
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Malo']])->assertOk()->assertJson(['actualizadas' => 1]);
        $this->assertSame(ConductaService::OBSERVACIONES['Malo'], Conducta::first()->descripcion);
    }

    public function test_validaciones(): void
    {
        $ana = $this->matriculas['Ana López'];
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Regular']])
            ->assertStatus(422)->assertJsonValidationErrors('conductas.0.valoracion');
        $this->guardar([['matricula_id' => $ana, 'valoracion' => '']])
            ->assertStatus(422)->assertJsonValidationErrors('conductas.0.valoracion');
        $this->guardar([['matricula_id' => $this->matriculas['Pedro Pendiente'], 'valoracion' => 'Bueno']])
            ->assertStatus(422)->assertJsonValidationErrors('conductas');

        $otroAnio = AnioLectivo::create(['anio' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $ajeno = PeriodoEscolar::create(['nombre' => 'P', 'nivel' => 1, 'anio_lectivo_id' => $otroAnio->id]);
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Bueno']], null, $ajeno)
            ->assertStatus(422)->assertJsonValidationErrors('periodo_escolar_id');

        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->guardar([['matricula_id' => $ana, 'valoracion' => 'Bueno']])->assertStatus(409);
        $this->assertSame(0, Conducta::count());
    }

    public function test_solo_docentes_de_la_seccion(): void
    {
        [$ajeno] = $this->docenteNuevo(3);
        $this->como($ajeno)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta")->assertNotFound();
        $this->guardar([['matricula_id' => $this->matriculas['Ana López'], 'valoracion' => 'Bueno']], $ajeno)->assertNotFound();
        $this->como($this->encargado)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta")->assertForbidden();
        $this->assertSame(0, Conducta::count());
    }
}

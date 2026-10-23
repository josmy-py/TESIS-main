<?php

namespace Tests\Feature\M7;

use App\Models\Encargado;
use App\Models\Matricula;
use App\Models\Padre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Mis estudiantes > Ver (pedido de Josmy del 23/10/2026): ficha del estudiante con
 * padres (con DUI), encargado de la matrícula y si proviene de otra institución.
 */
class FichaEstudianteTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
    }

    private function url($matriculaId, $asignacion = null): string
    {
        return '/api/docente/asignaciones/'.($asignacion ?? $this->matematica)->id."/estudiantes/{$matriculaId}";
    }

    public function test_ficha_con_padres_encargado_y_traslado(): void
    {
        $ana = $this->estudiantes['Ana López'];
        $ana->update(['fecha_nacimiento' => '2018-04-09', 'genero' => 'Femenino']);
        $madre = Padre::create(['nombre' => 'María López', 'dui' => '01234567-8', 'telefono' => '7000-1111', 'email' => 'maria@correo.test']);
        $ana->padres()->attach($madre->id, ['parentesco' => 'Madre']);
        $tia = Encargado::create(['nombre' => 'Rosa López', 'dui' => '09876543-2', 'parentesco' => 'Tío(a)', 'telefono' => '7000-2222', 'direccion' => 'X']);
        Matricula::whereKey($this->matriculas['Ana López'])->update(['encargado_id' => $tia->id, 'es_trasladado' => true]);

        $r = $this->como($this->cuentaDocente)->getJson($this->url($this->matriculas['Ana López']))->assertOk();
        $r->assertJsonPath('estudiante.nombre', 'Ana López')
            ->assertJsonPath('estudiante.fecha_nacimiento', '2018-04-09')
            ->assertJsonPath('estudiante.genero', 'Femenino')
            ->assertJsonPath('es_trasladado', true)
            ->assertJsonPath('padres.0', ['nombre' => 'María López', 'parentesco' => 'Madre', 'dui' => '01234567-8', 'telefono' => '7000-1111', 'email' => 'maria@correo.test'])
            ->assertJsonPath('encargado', ['nombre' => 'Rosa López', 'dui' => '09876543-2', 'parentesco' => 'Tío(a)', 'telefono' => '7000-2222']);

        // Sin padres, sin encargado y no trasladado
        $r = $this->como($this->cuentaDocente)->getJson($this->url($this->matriculas['Zoila Pérez']))->assertOk();
        $r->assertJsonPath('padres', [])->assertJsonPath('encargado', null)->assertJsonPath('es_trasladado', false);
    }

    public function test_solo_matriculas_aprobadas_de_secciones_propias(): void
    {
        // Matrícula pendiente o inexistente
        $this->como($this->cuentaDocente)->getJson($this->url($this->matriculas['Pedro Pendiente']))->assertNotFound();
        $this->como($this->cuentaDocente)->getJson($this->url(999))->assertNotFound();
        // Asignación de otro docente
        $this->como($this->cuentaDocente)->getJson($this->url($this->matriculas['Ana López'], $this->lenguaje))->assertNotFound();
        [$ajeno] = $this->docenteNuevo(3);
        $this->como($ajeno)->getJson($this->url($this->matriculas['Ana López']))->assertNotFound();
        $this->como($this->encargado)->getJson($this->url($this->matriculas['Ana López']))->assertForbidden();
        $this->como($this->usuario(User::ROL_DIRECTOR))->getJson($this->url($this->matriculas['Ana López']))->assertForbidden();
    }
}

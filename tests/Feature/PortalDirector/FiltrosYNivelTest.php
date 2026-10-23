<?php

namespace Tests\Feature\PortalDirector;

use App\Models\AnioLectivo;
use App\Models\Conducta;
use App\Models\Grado;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Pedido de Josmy (23/10/2026) para el portal del director:
 * - Matrículas: filtro por año lectivo.
 * - Estudiantes: filtros por año lectivo, grado y sección.
 * - Periodos: "nivel" es el nivel académico (1 Parvularia y Básica, 2 Bachillerato).
 */
class FiltrosYNivelTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
    }

    /** Los catálogos que ya sembró prepararSeccion() (no se pueden crear dos veces). */
    private function catalogosExistentes(): array
    {
        return [
            'distrito' => DB::table('distritos')->value('id'),
            'especialidad' => DB::table('especialidades')->value('id'),
            'enfermedad' => DB::table('enfermedades')->value('id'),
        ];
    }

    /** Otro año con una sección de bachillerato y un estudiante aprobado en ella. */
    private function bachillerato(): array
    {
        $anio = AnioLectivo::create(['anio' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $grado = Grado::create(['nombre' => 'Primer año de bachillerato']);
        $seccion = Seccion::create([
            'nombre' => 'B', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $anio->id,
            'grado_id' => $grado->id, 'profesor_id' => $this->seccion->profesor_id,
        ]);
        $catalogos = $this->catalogosExistentes();
        $estudiante = $this->estudianteDe($this->encargado, $catalogos['distrito'], ['nombre' => 'Bea Bachiller']);
        $this->matriculaDe($estudiante, $catalogos, ['estado' => 'aprobado', 'seccion_id' => $seccion->id, 'anio_lectivo_id' => $anio->id]);
        DB::table('estudiantes')->update(['estado' => 'activo']);

        return [$anio, $grado, $seccion];
    }

    public function test_matriculas_se_filtran_por_anio(): void
    {
        [$anio] = $this->bachillerato();

        $r = $this->como($this->director)->getJson("/api/matriculas?estado=aprobado&anio_lectivo_id={$anio->id}")->assertOk();
        $this->assertSame(['Bea Bachiller'], collect($r->json('matriculas'))->pluck('estudiante.nombre')->all());
        $this->assertSame(1, $r->json('resumen.aprobado'));

        $r = $this->como($this->director)->getJson("/api/matriculas?estado=aprobado&anio_lectivo_id={$this->anio->id}")->assertOk();
        $this->assertSame(2, $r->json('resumen.aprobado'));
    }

    public function test_estudiantes_se_filtran_por_anio_grado_y_seccion(): void
    {
        [$anio, $grado, $seccion] = $this->bachillerato();
        $listar = fn (string $filtros) => collect($this->como($this->director)
            ->getJson("/api/estudiantes?estado=activo&con_matricula_aprobada=1&{$filtros}")->assertOk()->json('estudiantes'));

        $this->assertSame(['Ana López', 'Bea Bachiller', 'Zoila Pérez'], $listar('')->pluck('nombre')->all());
        $this->assertSame(['Bea Bachiller'], $listar("anio_lectivo_id={$anio->id}")->pluck('nombre')->all());
        $this->assertSame(['Ana López', 'Zoila Pérez'], $listar("grado_id={$this->seccion->grado_id}")->pluck('nombre')->all());
        $this->assertSame(['Bea Bachiller'], $listar("anio_lectivo_id={$anio->id}&grado_id={$grado->id}&seccion_id={$seccion->id}")->pluck('nombre')->all());
        $this->assertSame([], $listar("anio_lectivo_id={$this->anio->id}&seccion_id={$seccion->id}")->all());

        // La fila muestra la matrícula que cumple los filtros
        $fila = $listar("seccion_id={$seccion->id}")->first();
        $this->assertSame('B', $fila['matriculas'][0]['seccion']['nombre']);
        $this->assertSame(2027, (int) $fila['matriculas'][0]['anio_lectivo']['anio']);

        // Pedro (matrícula pendiente) no aparece aunque su sección coincida
        $this->assertNotContains('Pedro Pendiente', $listar("seccion_id={$this->seccion->id}")->pluck('nombre')->all());
    }

    public function test_cada_seccion_usa_los_periodos_de_su_nivel(): void
    {
        $bach = PeriodoEscolar::create(['nombre' => 'Primer trimestre', 'nivel' => 2, 'anio_lectivo_id' => $this->anio->id]);

        // Primer grado (nivel 1): no ve ni acepta el periodo de bachillerato
        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/asignaciones/{$this->matematica->id}/estudiantes")->assertOk();
        $this->assertSame(collect($this->periodos)->pluck('id')->all(), collect($r->json('periodos'))->pluck('id')->all());
        $this->como($this->cuentaDocente)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta?periodo_escolar_id={$bach->id}")
            ->assertStatus(422)->assertJsonPath('errors.periodo_escolar_id.0', 'El periodo no pertenece al año lectivo ni al nivel de la sección.');

        // Una sección de bachillerato del mismo año solo ve el suyo
        $seccion = Seccion::create([
            'nombre' => 'B', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => Grado::create(['nombre' => 'Segundo año de bachillerato'])->id, 'profesor_id' => $this->seccion->profesor_id,
        ]);
        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/secciones/{$seccion->id}/conducta")->assertOk();
        $this->assertSame([$bach->id], collect($r->json('periodos'))->pluck('id')->all());
    }

    public function test_un_periodo_activo_por_nivel(): void
    {
        $bach = PeriodoEscolar::create(['nombre' => 'Primer trimestre', 'nivel' => 2, 'anio_lectivo_id' => $this->anio->id]);
        $estado = fn ($p, $e) => $this->como($this->director)->putJson("/api/periodos-escolares/{$p->id}/estado", ['estado' => $e]);

        $estado($this->periodos[0], 'activo')->assertOk();
        $estado($this->periodos[1], 'activo')->assertStatus(409);
        // Bachillerato lleva su propio calendario
        $estado($bach, 'activo')->assertOk()->assertJsonPath('periodo.nivel_nombre', 'Bachillerato');
    }

    public function test_el_nivel_no_se_edita_despues_de_crear_el_periodo(): void
    {
        // Pedido de Josmy (23/10/2026): al editar solo cambia el nombre; un nivel enviado se ignora
        $this->como($this->director)->putJson("/api/periodos-escolares/{$this->periodos[1]->id}", ['nombre' => 'Segundo periodo', 'nivel' => 2])
            ->assertOk()->assertJsonPath('periodo.nombre', 'Segundo periodo')->assertJsonPath('periodo.nivel', 1);
        $this->assertSame(1, (int) $this->periodos[1]->fresh()->nivel);
    }

    public function test_migracion_pasa_los_periodos_a_nivel_academico_sin_perder_registros(): void
    {
        // Datos como antes de la migración: nivel = número del periodo
        Artisan::call('migrate:rollback', ['--step' => 1]);
        PeriodoEscolar::whereKey($this->periodos[1]->id)->update(['nivel' => 2]);
        $seccionBach = Seccion::create([
            'nombre' => 'B', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => Grado::create(['nombre' => 'Primer año de bachillerato'])->id, 'profesor_id' => $this->seccion->profesor_id,
        ]);
        $catalogos = $this->catalogosExistentes();
        $bea = $this->matriculaDe($this->estudianteDe($this->encargado, $catalogos['distrito'], ['nombre' => 'Bea']), $catalogos,
            ['estado' => 'aprobado', 'seccion_id' => $seccionBach->id, 'anio_lectivo_id' => $this->anio->id]);
        $deBasica = Conducta::create(['descripcion' => 'A', 'valoracion' => 'Excelente', 'matricula_id' => $this->matriculas['Ana López'], 'periodo_escolar_id' => $this->periodos[1]->id]);
        $deBach = Conducta::create(['descripcion' => 'B', 'valoracion' => 'Excelente', 'matricula_id' => $bea, 'periodo_escolar_id' => $this->periodos[1]->id]);
        $antes = PeriodoEscolar::orderBy('id')->get(['id', 'nombre', 'nivel', 'estado'])->toArray();

        Artisan::call('migrate');

        // Los periodos existentes quedan en nivel 1 y bachillerato recibe su copia de cada uno
        $this->assertSame([1, 1, 2, 2], PeriodoEscolar::orderBy('id')->pluck('nivel')->all());
        $copia = PeriodoEscolar::where('nivel', 2)->where('nombre', $this->periodos[1]->nombre)->firstOrFail();
        $this->assertSame($this->periodos[1]->id, $deBasica->fresh()->periodo_escolar_id);
        $this->assertSame($copia->id, $deBach->fresh()->periodo_escolar_id);

        // Revertir devuelve todo como estaba
        Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertSame($antes, PeriodoEscolar::orderBy('id')->get(['id', 'nombre', 'nivel', 'estado'])->toArray());
        $this->assertSame($this->periodos[1]->id, $deBach->fresh()->periodo_escolar_id);
        Artisan::call('migrate');
    }
}

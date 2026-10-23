<?php

namespace Tests\Feature\M9;

use App\Models\AnioLectivo;
use App\Models\Asignacion;
use App\Models\Boleta;
use App\Models\Calificacion;
use App\Models\Conducta;
use App\Models\Matricula;
use App\Models\NotaFinal;
use App\Models\PeriodoEscolar;
use App\Services\CalendarioService;
use App\Services\LibretaService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SeccionPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DatosAcademicosPruebaSeeder (20/10/2026): matrículas de muestra con notas,
 * conducta, boletas y nota final, coherentes con las reglas de M4, M7, M8 y M9.
 */
class DatosAcademicosPruebaSeederTest extends TestCase
{
    use RefreshDatabase;

    private function sembrar(): void
    {
        config(['app.seed_password' => 'ClaveSemilla123']);
        $this->seed(DatabaseSeeder::class);
    }

    private function deMuestra()
    {
        return Matricula::whereHas('estudiante', fn ($q) => $q->where('nie', 'like', '910%'));
    }

    public function test_crea_las_matriculas_de_muestra_y_activa_el_anio_de_prueba(): void
    {
        $this->sembrar();

        $anio = AnioLectivo::activo();
        $this->assertNotNull($anio);
        $this->assertSame(SeccionPruebaSeeder::ANIO_PRUEBA, $anio->anio);

        $this->assertSame(8, $this->deMuestra()->where('estado', 'aprobado')->whereNotNull('seccion_id')->count());
        $this->assertSame(1, $this->deMuestra()->where('estado', 'pendiente')->whereNull('seccion_id')->count());
        $this->assertSame(1, $this->deMuestra()->where('estado', 'rechazado')->whereNull('seccion_id')->count());

        $foto = $this->deMuestra()->first()->foto;
        Storage::disk('matriculas')->assertExists($foto);
    }

    public function test_notas_boletas_y_nota_final_siguen_la_regla_de_la_libreta(): void
    {
        $this->sembrar();

        $aprobada = $this->deMuestra()->where('estado', 'aprobado')->first();
        $asignaciones = Asignacion::where('seccion_id', $aprobada->seccion_id)->count();
        // Periodos del nivel académico de su sección
        $periodos = PeriodoEscolar::deLaSeccion($aprobada->seccion)->count();

        // Las 4 materias del pensum de prueba, cada una por AsignacionService
        $this->assertSame(4, $asignaciones);
        $this->assertSame($asignaciones * $periodos, Boleta::where('matricula_id', $aprobada->id)->count());
        $this->assertSame($periodos, Conducta::where('matricula_id', $aprobada->id)->count());

        $final = NotaFinal::where('matricula_id', $aprobada->id)->first();
        $this->assertNotNull($final);
        $esperada = app(LibretaService::class)->calcularNotaFinal($aprobada);
        $this->assertEquals($esperada->promedio_anual, $final->fresh()->promedio_anual);
        $this->assertContains($final->estado, [LibretaService::APROBADA, LibretaService::REPROBADA]);

        // Las solicitudes pendientes o rechazadas no tienen notas
        $sinNotas = $this->deMuestra()->where('estado', '<>', 'aprobado')->pluck('id');
        $this->assertSame(0, Calificacion::whereIn('matricula_id', $sinNotas)->count());
    }

    public function test_es_idempotente(): void
    {
        $this->sembrar();
        $antes = [Matricula::count(), Calificacion::count(), Boleta::count(), Conducta::count(), NotaFinal::count(), Asignacion::count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($antes, [Matricula::count(), Calificacion::count(), Boleta::count(), Conducta::count(), NotaFinal::count(), Asignacion::count()]);
    }

    public function test_no_registra_notas_en_periodos_cerrados(): void
    {
        config(['app.seed_password' => 'ClaveSemilla123']);
        // Primera pasada sin datos académicos: deja el año y los periodos listos
        $this->seed(DatabaseSeeder::class);
        Calificacion::query()->delete();
        Boleta::query()->delete();
        Conducta::query()->delete();
        NotaFinal::query()->delete();

        $primero = PeriodoEscolar::where('anio_lectivo_id', AnioLectivo::activo()->id)->orderBy('nivel')->first();
        app(CalendarioService::class)->cambiarEstadoPeriodo($primero->id, PeriodoEscolar::ACTIVO);
        app(CalendarioService::class)->cambiarEstadoPeriodo($primero->id, PeriodoEscolar::CERRADO);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Boleta::where('periodo_escolar_id', $primero->id)->count());
        $this->assertSame(0, Conducta::where('periodo_escolar_id', $primero->id)->count());
        // Sin todas las boletas del año no hay nota final
        $this->assertSame(0, NotaFinal::count());
    }
}

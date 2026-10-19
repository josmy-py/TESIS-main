<?php

namespace Tests\Feature\M6;

use App\Models\User;
use App\Support\RespaldoAlineacion;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M6.1: las migraciones 000010 (campos opcionales y `notifications`) y 000011
 * (archivos al disco privado) se revierten sin perder datos, y se detienen
 * cuando revertir los perdería.
 */
class MigracionM6Test extends TestCase
{
    use DatabaseMigrations, DatosM1;

    private array $catalogos;

    private User $encargado;

    /** Migraciones posteriores a 000011 (usuarios/roles y profesores como el diagrama). */
    private const POSTERIORES = 6;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(RespaldoAlineacion::directorio());
        Storage::fake('public');
        Storage::fake('matriculas');
        $this->sembrarRoles();
        $this->catalogos = $this->catalogosBasicos();
        // Se crea antes de revertir: con el esquema anterior la cuenta no tiene rol_id
        $this->encargado = $this->usuario(User::ROL_ENCARGADO, ['email' => 'enc@prueba.test']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(RespaldoAlineacion::directorio());
        parent::tearDown();
    }

    private function matricula(array $atributos): int
    {
        return $this->matriculaDe($this->estudianteDe($this->encargado, $this->catalogos['distrito']), $this->catalogos, $atributos);
    }

    public function test_archivos_publicos_pasan_al_disco_privado_y_vuelven(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1 + self::POSTERIORES]));

        Storage::disk('public')->put('foto/a.jpg', 'foto A');
        Storage::disk('public')->put('documentos/b.pdf', 'partida B');
        $conArchivos = $this->matricula(['foto' => '/storage/foto/a.jpg', 'documento' => '/storage/documentos/b.pdf']);
        $faltante = $this->matricula(['foto' => '/storage/foto/no-esta.jpg']);

        $this->assertSame(0, Artisan::call('migrate'));

        $fila = DB::table('matriculas')->find($conArchivos);
        $this->assertSame(['fotos/a.jpg', 'documentos/b.pdf'], [$fila->foto, $fila->documento]);
        $this->assertSame('foto A', Storage::disk('matriculas')->get('fotos/a.jpg'));
        $this->assertSame('partida B', Storage::disk('matriculas')->get('documentos/b.pdf'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        // El que no existe se deja igual y se informa
        $this->assertSame('/storage/foto/no-esta.jpg', DB::table('matriculas')->where('id', $faltante)->value('foto'));
        $bitacora = json_decode(File::get(File::glob(RespaldoAlineacion::directorio().'/m6-archivos-matriculas-*.json')[0]), true);
        $this->assertCount(2, $bitacora['movidos']);
        $this->assertSame($faltante, $bitacora['faltantes'][0]['matricula_id']);

        // Revertir devuelve archivos y rutas
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1 + self::POSTERIORES]));
        $fila = DB::table('matriculas')->find($conArchivos);
        $this->assertSame(['/storage/foto/a.jpg', '/storage/documentos/b.pdf'], [$fila->foto, $fila->documento]);
        $this->assertSame('foto A', Storage::disk('public')->get('foto/a.jpg'));
        $this->assertSame([], Storage::disk('matriculas')->allFiles());
    }

    public function test_revertir_campos_opcionales_se_detiene_si_perderia_datos(): void
    {
        $vacia = $this->matricula(['especialidad_id' => null, 'enfermedad_id' => null]);
        $this->matricula([]);

        try {
            Artisan::call('migrate:rollback', ['--step' => 2 + self::POSTERIORES]);
            $this->fail('Debió detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 matrícula(s) no tienen especialidad o enfermedad', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('notifications'));
        $this->assertNull(DB::table('matriculas')->where('id', $vacia)->value('especialidad_id'));

        // Sin vacíos: se revierte, respaldando los avisos, y se vuelve a aplicar
        DB::table('matriculas')->where('id', $vacia)->update(['especialidad_id' => $this->catalogos['especialidad'], 'enfermedad_id' => $this->catalogos['enfermedad']]);
        DB::table('notifications')->insert(['id' => 'b3f0a1c2-0000-4000-8000-000000000001', 'type' => 'App\\Notifications\\MatriculaRechazada',
            'notifiable_type' => User::class, 'notifiable_id' => 1, 'data' => '{"motivo":"Prueba"}', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 2]));
        $this->assertFalse(Schema::hasTable('notifications'));
        $respaldo = json_decode(File::get(File::glob(RespaldoAlineacion::directorio().'/m6-notificaciones-*.json')[0]), true);
        $this->assertSame('{"motivo":"Prueba"}', $respaldo['notifications'][0]['data']);

        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertFalse(Schema::hasTable('notifications'), 'notifications no está en el diagrama');
        $this->assertTrue(collect(Schema::getColumns('matriculas'))->firstWhere('name', 'enfermedad_id')['nullable']);
    }
}

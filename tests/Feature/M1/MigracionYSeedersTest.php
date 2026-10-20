<?php

namespace Tests\Feature\M1;

use App\Models\Rol;
use App\Models\User;
use App\Support\RespaldoAlineacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigracionYSeedersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(RespaldoAlineacion::directorio());
    }

    public function test_seeders_idempotentes(): void
    {
        config(['app.seed_password' => 'ClaveSemilla123']);

        $this->seed(DatabaseSeeder::class);
        $hash = User::where('email', 'director@siga.test')->value('password');
        $this->seed(DatabaseSeeder::class);

        // 3 cuentas de UsuarioPruebaSeeder + encargado.aprobado@siga.test (DatosAcademicosPruebaSeeder)
        $this->assertSame(4, User::count());
        $this->assertSame(['DIRECTOR', 'DOCENTE', 'ENCARGADO'], Rol::orderBy('nombre')->pluck('nombre')->all());
        $this->assertSame(0, User::whereNull('rol_id')->count());
        $this->assertSame($hash, User::where('email', 'director@siga.test')->value('password'));
        $this->assertTrue(Hash::check('ClaveSemilla123', $hash));
    }

    /**
     * Base con las tablas de roles anteriores (roles con name, model_has_roles)
     * y users con nombre, DUI y estado: al migrar, cada cuenta conserva su rol
     * en users.rol_id y lo quitado queda respaldado; revertir lo devuelve.
     */
    public function test_migracion_traslada_roles_a_rol_id_y_respalda_lo_quitado(): void
    {
        $this->revertirDesde('2026_10_13_000012_usuarios_y_roles_como_el_diagrama');
        $this->assertTrue(Schema::hasColumn('users', 'dui'));

        $u = fn ($email, $dui) => DB::table('users')->insertGetId(['email' => $email, 'nombre' => 'N '.$email, 'dui' => $dui, 'estado' => 'activo', 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()]);
        $encargado = $u('enc@x.com', '00016297-5');
        $director = $u('dir@x.com', '00000001-8');
        $sinRol = $u('sinrol@x.com', null);

        $roles = DB::table('roles')->pluck('id', 'name');
        DB::table('model_has_roles')->insert([
            ['role_id' => $roles['ENCARGADO'], 'model_type' => User::class, 'model_id' => $encargado],
            ['role_id' => $roles['DIRECTOR'], 'model_type' => User::class, 'model_id' => $director],
        ]);

        Artisan::call('migrate');

        $this->assertFalse(Schema::hasTable('model_has_roles'));
        $this->assertFalse(Schema::hasColumn('users', 'dui'));
        $this->assertSame('ENCARGADO', User::find($encargado)->rol());
        $this->assertSame('DIRECTOR', User::find($director)->rol());
        $this->assertNull(User::find($sinRol)->rol());
        $this->assertSame('00016297-5', collect(RespaldoAlineacion::leer('usuarios-roles')['users'])->firstWhere('id', $encargado)['dui']);

        $this->revertirDesde('2026_10_13_000012_usuarios_y_roles_como_el_diagrama');

        $this->assertSame('00016297-5', DB::table('users')->where('id', $encargado)->value('dui'));
        $this->assertSame($director, (int) DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'DIRECTOR')->value('model_id'));
    }

    /**
     * Deshace la migración indicada y todas las posteriores, para que la
     * prueba no dependa de cuántas migraciones se añadan después.
     */
    private function revertirDesde(string $migracion): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', $migracion)->count();
        Artisan::call('migrate:rollback', ['--step' => $pasos]);
    }
}

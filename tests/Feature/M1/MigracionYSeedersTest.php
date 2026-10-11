<?php

namespace Tests\Feature\M1;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MigracionYSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_idempotentes(): void
    {
        config(['app.seed_password' => 'ClaveSemilla123']);

        $this->seed(DatabaseSeeder::class);
        $hash = User::where('email', 'director@siga.test')->value('password');
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertSame(['DIRECTOR', 'DOCENTE', 'ENCARGADO'], Role::orderBy('name')->pluck('name')->all());
        $this->assertSame(3, DB::table('model_has_roles')->count());
        $this->assertSame($hash, User::where('email', 'director@siga.test')->value('password'));
        $this->assertTrue(Hash::check('ClaveSemilla123', $hash));
        $this->assertFalse(Role::where('name', 'CLIENTE')->exists());
        $this->assertSame(0, User::where('estado', '!=', 'activo')->count());
    }

    /**
     * Base con datos anteriores a M1: usuarios sin nombre/DUI/estado, rol
     * CLIENTE asignado y perfiles vinculados. Se deshacen las migraciones de
     * M1, se cargan esos datos y se vuelven a aplicar.
     */
    public function test_migracion_conserva_usuarios_y_traslada_cliente_a_encargado(): void
    {
        $this->revertirDesde('2026_10_09_000001_add_nombre_dui_estado_to_users_table');
        $this->assertFalse(\Schema::hasColumn('users', 'dui'));

        $u = fn ($email) => DB::table('users')->insertGetId(['email' => $email, 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()]);
        $cliente = $u('cliente@x.com');      // perfil padre con DUI válido -> se recupera
        $profe = $u('profe@x.com');          // perfil profesor
        $sinPerfil = $u('sinperfil@x.com');  // queda sin DUI (requiere intervención)
        $dup1 = $u('dup1@x.com');            // dos cuentas con el mismo DUI de perfil -> ninguna lo recibe
        $dup2 = $u('dup2@x.com');
        $director = $u('director@x.com');

        $rol = fn ($name) => DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
        $rCliente = $rol('CLIENTE');
        $rDirector = $rol('DIRECTOR');
        $rDocente = $rol('DOCENTE');
        foreach ([[$rCliente, $cliente], [$rCliente, $sinPerfil], [$rCliente, $dup1], [$rCliente, $dup2], [$rDocente, $profe], [$rDirector, $director]] as [$r, $m]) {
            DB::table('model_has_roles')->insert(['role_id' => $r, 'model_type' => User::class, 'model_id' => $m]);
        }

        DB::table('padres')->insert(['nombre' => 'Padre Cliente', 'dui' => '000162975', 'user_id' => $cliente]);
        DB::table('profesores')->insert(['nombre' => 'Profe Uno', 'dui' => '00000001-8', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P1', 'telefono' => '7000-0000', 'direccion' => 'X', 'user_id' => $profe]);
        DB::table('padres')->insert(['nombre' => 'Dup Uno', 'dui' => '00000002-6', 'user_id' => $dup1]);
        DB::table('encargados')->insert(['nombre' => 'Dup Dos', 'parentesco' => 'Otro', 'telefono' => '2222', 'dui' => '00000002-6', 'direccion' => 'X', 'user_id' => $dup2]);

        $antes = DB::table('users')->count();
        Artisan::call('migrate');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame($antes, DB::table('users')->count());
        $this->assertSame(0, DB::table('users')->where('estado', '!=', 'activo')->count());

        $this->assertSame('00016297-5', User::find($cliente)->dui);
        $this->assertSame('Padre Cliente', User::find($cliente)->nombre);
        $this->assertSame('00000001-8', User::find($profe)->dui);
        $this->assertNull(User::find($sinPerfil)->dui);
        $this->assertNull(User::find($dup1)->dui);
        $this->assertNull(User::find($dup2)->dui);

        // El rol conserva su id y sus asignaciones
        $this->assertFalse(Role::where('name', 'CLIENTE')->exists());
        $this->assertSame($rCliente, Role::where('name', 'ENCARGADO')->value('id'));
        foreach ([$cliente, $sinPerfil, $dup1, $dup2] as $id) {
            $this->assertSame(['ENCARGADO'], User::find($id)->getRoleNames()->all());
        }
        $this->assertSame(['DIRECTOR'], User::find($director)->getRoleNames()->all());

        // Las cuentas que requieren intervención se listan
        Artisan::call('siga:usuarios-sin-dui');
        $salida = Artisan::output();
        $this->assertStringContainsString('sinperfil@x.com', $salida);
        $this->assertStringContainsString('dup1@x.com', $salida);
        $this->assertStringNotContainsString('cliente@x.com', $salida);
    }

    public function test_si_ya_existe_encargado_se_trasladan_asignaciones_sin_duplicar(): void
    {
        $this->revertirDesde('2026_10_09_000002_rename_cliente_role_to_encargado');

        $user = DB::table('users')->insertGetId(['email' => 'a@x.com', 'password' => 'x', 'estado' => 'activo']);
        $otro = DB::table('users')->insertGetId(['email' => 'b@x.com', 'password' => 'x', 'estado' => 'activo']);
        $rCliente = DB::table('roles')->insertGetId(['name' => 'CLIENTE', 'guard_name' => 'api']);
        $rEncargado = DB::table('roles')->insertGetId(['name' => 'ENCARGADO', 'guard_name' => 'api']);
        DB::table('model_has_roles')->insert([
            ['role_id' => $rCliente, 'model_type' => User::class, 'model_id' => $user],
            ['role_id' => $rEncargado, 'model_type' => User::class, 'model_id' => $user],
            ['role_id' => $rCliente, 'model_type' => User::class, 'model_id' => $otro],
        ]);

        Artisan::call('migrate');

        $this->assertFalse(DB::table('roles')->where('name', 'CLIENTE')->exists());
        $this->assertSame(2, DB::table('model_has_roles')->where('role_id', $rEncargado)->count());
        $this->assertSame(2, DB::table('model_has_roles')->count());
    }

    /**
     * Deshace la migración indicada y todas las posteriores (las de cierre de
     * M1 y las de módulos siguientes), para que la prueba no dependa de cuántas
     * migraciones se añadan después.
     */
    private function revertirDesde(string $migracion): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', $migracion)->count();
        Artisan::call('migrate:rollback', ['--step' => $pasos]);
    }
}

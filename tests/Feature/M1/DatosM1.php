<?php

namespace Tests\Feature\M1;

use App\Models\Estudiante;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Datos y utilidades compartidas por las pruebas de M1.
 */
trait DatosM1
{
    protected function sembrarRoles(): void
    {
        $this->seed(RoleSeeder::class);
    }

    protected function usuario(string $rol, array $atributos = []): User
    {
        return User::factory()->conRol($rol)->create($atributos);
    }

    protected function tokenDe(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    /**
     * Cabecera Bearer de la cuenta indicada. Se olvidan los guards para que
     * cada petición resuelva el usuario desde su propio token.
     */
    protected function como(User $user)
    {
        return $this->conToken($this->tokenDe($user));
    }

    protected function conToken(string $token)
    {
        // En producción cada petición es un proceso nuevo; en las pruebas el
        // objeto JWT es compartido y conserva el token anterior.
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    protected function catalogosBasicos(): array
    {
        $departamento = DB::table('departamentos')->insertGetId(['nombre' => 'San Salvador']);
        $municipio = DB::table('municipios')->insertGetId(['nombre' => 'San Salvador Centro', 'departamento_id' => $departamento]);
        $distrito = DB::table('distritos')->insertGetId(['nombre' => 'San Salvador', 'municipio_id' => $municipio]);
        $especialidad = DB::table('especialidades')->insertGetId(['nombre' => 'General']);
        $enfermedad = DB::table('enfermedades')->insertGetId(['nombre' => 'Ninguna', 'medicamento' => 'Ninguno']);

        return compact('distrito', 'especialidad', 'enfermedad');
    }

    protected function estudianteDe(User $user, int $distritoId, array $atributos = []): Estudiante
    {
        return Estudiante::create(array_merge([
            'nombre' => 'Estudiante de '.$user->email,
            'fecha_nacimiento' => '2012-05-10',
            'genero' => 'Femenino',
            'distrito_id' => $distritoId,
            'nie' => (string) random_int(1000000, 9999999),
            'direccion' => 'Calle 1',
            'estado' => 'inscrito',
            'user_id' => $user->id,
        ], $atributos));
    }

    protected function matriculaDe(Estudiante $estudiante, array $catalogos, array $atributos = []): int
    {
        return DB::table('matriculas')->insertGetId(array_merge([
            'ingreso' => 'Nuevo ingreso',
            'estado' => 'pendiente',
            'foto' => '/storage/foto/x.jpg',
            'es_trasladado' => false,
            'estudiante_id' => $estudiante->id,
            'especialidad_id' => $catalogos['especialidad'],
            'enfermedad_id' => $catalogos['enfermedad'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $atributos));
    }
}

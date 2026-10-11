<?php

namespace Tests\Feature\M1;

use App\Models\Padre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cierre de M1: propiedad de los padres y revocación de sesiones al cambiar
 * o restablecer la contraseña.
 */
class CierreSeguridadTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    private User $a;
    private User $b;
    private array $catalogos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->catalogos = $this->catalogosBasicos();
        $this->a = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $this->b = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000002-6']);
    }

    private function vincular(User $cuenta, int $estudianteId, int $padreId)
    {
        return $this->como($cuenta)->postJson('/api/parientes', [
            'estudiante_id' => $estudianteId, 'padre_id' => $padreId, 'parentesco' => 'Madre',
        ]);
    }

    // Un padre recién registrado (sin cuenta ni estudiantes) solo lo vincula quien lo registró
    public function test_padre_sin_estudiantes_no_lo_vincula_otro_encargado(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);

        $respuesta = $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Madre de A', 'dui' => '00000003-4', 'es_encargado' => false])
            ->assertCreated()->assertJsonMissingPath('padre.registrado_por');
        $madre = $respuesta->json('padre.id');
        $this->assertSame($this->a->id, Padre::find($madre)->registrado_por);

        // B no lo encuentra ni lo vincula, aunque conozca el id
        $this->como($this->b)->postJson('/api/padres/buscar-dui', ['dui' => '00000003-4'])->assertNotFound();
        $this->vincular($this->b, $deB->id, $madre)->assertNotFound();
        $this->assertSame(0, DB::table('parientes')->count());

        // A sí lo encuentra y lo vincula
        $this->como($this->a)->postJson('/api/padres/buscar-dui', ['dui' => '000000034'])->assertOk()->assertJsonPath('padre.id', $madre);
        $this->vincular($this->a, $deA->id, $madre)->assertCreated();

        // Después de vincularlo, B sigue sin acceso
        $this->vincular($this->b, $deB->id, $madre)->assertNotFound();
        $this->como($this->b)->getJson("/api/matriculas/estudiantes/{$deA->id}/padres")->assertNotFound();
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$deA->id}/padres")->assertOk()->assertJsonPath('padres.0.id', $madre);
    }

    // B no puede registrar de nuevo a la misma persona para quedarse con el registro
    public function test_otro_encargado_no_se_apropia_registrando_el_mismo_dui(): void
    {
        $madre = $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Madre', 'dui' => '00000003-4', 'es_encargado' => false])
            ->assertCreated()->json('padre.id');

        $this->como($this->b)->postJson('/api/padres', ['nombre' => 'Otra', 'dui' => '000000034', 'es_encargado' => false])
            ->assertStatus(422)->assertJsonValidationErrors('dui');

        $padre = Padre::find($madre);
        $this->assertSame('Madre', $padre->nombre);
        $this->assertSame($this->a->id, $padre->registrado_por);
    }

    // Registros anteriores sin registrante: se deniega a todo encargado; el director sí puede
    public function test_padre_heredado_sin_propiedad_comprobable_se_deniega(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $heredado = Padre::create(['nombre' => 'Padre heredado', 'dui' => '00016297-5']);

        foreach ([[$this->a, $deA], [$this->b, $deB]] as [$cuenta, $estudiante]) {
            $this->como($cuenta)->postJson('/api/padres/buscar-dui', ['dui' => '00016297-5'])->assertNotFound();
            $this->vincular($cuenta, $estudiante->id, $heredado->id)->assertNotFound();
        }

        $director = $this->usuario(User::ROL_DIRECTOR);
        $this->como($director)->postJson('/api/padres/buscar-dui', ['dui' => '00016297-5'])->assertOk();
        $this->vincular($director, $deA->id, $heredado->id)->assertCreated();

        // Ya vinculado al estudiante de A: A puede usarlo; B sigue sin acceso
        $otroDeA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->vincular($this->a, $otroDeA->id, $heredado->id)->assertCreated();
        $this->vincular($this->b, $deB->id, $heredado->id)->assertNotFound();
    }

    // Un padre registrado por el director no queda abierto a cualquier encargado
    public function test_padre_registrado_por_director_no_lo_toma_un_encargado(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $director = $this->usuario(User::ROL_DIRECTOR);

        $padre = $this->como($director)->postJson('/api/padres', ['nombre' => 'Padre', 'dui' => '00000003-4', 'es_encargado' => false])
            ->assertCreated()->json('padre.id');

        $this->vincular($this->a, $deA->id, $padre)->assertNotFound();
    }

    // Cambio de contraseña: las demás sesiones dejan de servir; la actual recibe un token nuevo
    public function test_cambio_de_contrasena_revoca_las_sesiones_anteriores(): void
    {
        $credenciales = ['email' => $this->a->email, 'password' => 'password'];
        $sesion1 = $this->postJson('/api/auth/login', $credenciales)->assertOk()->json('access_token');
        $sesion2 = $this->postJson('/api/auth/login', $credenciales)->assertOk()->json('access_token');

        $nuevo = $this->conToken($sesion1)->putJson('/api/auth/contrasena', [
            'actual' => 'password', 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234',
        ])->assertOk()->assertJsonPath('message', 'Contraseña actualizada correctamente')->json('access_token');

        $this->assertNotEmpty($nuevo);
        $this->conToken($nuevo)->getJson('/api/auth/me')->assertOk();

        $this->conToken($sesion1)->getJson('/api/auth/me')->assertStatus(401);
        $this->conToken($sesion2)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('codigo', 'sesion_revocada');
        $this->conToken($sesion2)->postJson('/api/auth/refresh')->assertStatus(401);
        $this->conToken($sesion2)->getJson('/api/matriculas')->assertStatus(401);

        $this->postJson('/api/auth/login', $credenciales)->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $this->a->email, 'password' => 'Nueva1234'])->assertOk();
    }

    // Restablecimiento: ningún token emitido antes sigue sirviendo
    public function test_restablecer_contrasena_revoca_las_sesiones_anteriores(): void
    {
        $anterior = $this->postJson('/api/auth/login', ['email' => $this->b->email, 'password' => 'password'])->json('access_token');
        $this->conToken($anterior)->getJson('/api/auth/me')->assertOk();

        $token = Password::createToken($this->b);
        $this->postJson('/api/auth/restablecer-contrasena', [
            'token' => $token, 'email' => $this->b->email, 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234',
        ])->assertOk();

        $this->conToken($anterior)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('codigo', 'sesion_revocada');
        $this->conToken($anterior)->postJson('/api/auth/refresh')->assertStatus(401);

        $nuevo = $this->postJson('/api/auth/login', ['email' => $this->b->email, 'password' => 'Nueva1234'])->assertOk()->json('access_token');
        $this->conToken($nuevo)->getJson('/api/auth/me')->assertOk();
    }

    // Un token sin huella válida (por ejemplo, emitido antes de esta corrección) se rechaza
    public function test_token_sin_huella_vigente_se_rechaza(): void
    {
        // El token normal lleva la huella, que no expone el hash de la contraseña
        $payload = json_decode(base64_decode(strtr(explode('.', $this->tokenDe($this->a))[1], '-_', '+/')), true);
        $this->assertSame($this->a->huellaContrasena(), $payload[User::CLAIM_CONTRASENA]);
        $this->assertStringNotContainsString($payload[User::CLAIM_CONTRASENA], $this->a->password);

        $sinHuella = JWTAuth::claims([User::CLAIM_CONTRASENA => null])->fromUser($this->a);
        $ajena = JWTAuth::claims([User::CLAIM_CONTRASENA => str_repeat('0', 32)])->fromUser($this->a);

        $this->conToken($sinHuella)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('codigo', 'sesion_revocada');
        $this->conToken($ajena)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('codigo', 'sesion_revocada');

    }

    // Cuentas heredadas sin DUI: el comando las lista y el director completa el DUI una vez, sin tocar el rol
    public function test_completar_dui_de_cuenta_heredada(): void
    {
        $director = $this->usuario(User::ROL_DIRECTOR);
        $heredada = $this->usuario(User::ROL_ENCARGADO, ['nombre' => null, 'dui' => null]);
        $otra = $this->usuario(User::ROL_ENCARGADO, ['dui' => null]);
        $rolAntes = DB::table('model_has_roles')->where('model_id', $heredada->id)->pluck('role_id')->all();

        Artisan::call('siga:usuarios-sin-dui');
        $this->assertStringContainsString($heredada->email, Artisan::output());

        $datos = ['nombre' => 'Ana Heredada', 'email' => $heredada->email, 'rol' => User::ROL_ENCARGADO];

        $this->como($director)->putJson("/api/usuarios/{$heredada->id}", $datos + ['dui' => '000162974'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');
        $this->como($director)->putJson("/api/usuarios/{$heredada->id}", $datos + ['dui' => $this->a->dui])
            ->assertStatus(422)->assertJsonValidationErrors('dui');

        $this->como($director)->putJson("/api/usuarios/{$heredada->id}", $datos + ['dui' => '000162975'])
            ->assertOk()->assertJsonPath('usuario.dui', '00016297-5')->assertJsonPath('usuario.rol', User::ROL_ENCARGADO);
        $this->assertSame($rolAntes, DB::table('model_has_roles')->where('model_id', $heredada->id)->pluck('role_id')->all());

        // Una vez completado ya no se puede cambiar
        $this->como($director)->putJson("/api/usuarios/{$heredada->id}", $datos + ['dui' => '00000003-4'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');

        // Editar sin DUI sigue funcionando para la cuenta que aún no lo tiene
        $this->como($director)->putJson("/api/usuarios/{$otra->id}", ['nombre' => $otra->nombre, 'email' => $otra->email, 'rol' => User::ROL_ENCARGADO])
            ->assertOk();
        $this->assertNull($otra->fresh()->dui);

        Artisan::call('siga:usuarios-sin-dui');
        $salida = Artisan::output();
        $this->assertStringNotContainsString($heredada->email, $salida);
        $this->assertStringContainsString($otra->email, $salida);
    }
}

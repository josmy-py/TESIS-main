<?php

namespace Tests\Feature\M1;

use App\Models\User;
use App\Notifications\ActivarCuenta;
use App\Notifications\RestablecerContrasena;
use App\Services\UsuarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
    }

    private function datosRegistro(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'María López',
            'dui' => '000162975',
            'email' => 'Maria@Correo.com ',
            'password' => 'Segura123',
            'password_confirmation' => 'Segura123',
        ], $cambios);
    }

    // 1. Registro: cuenta pendiente, rol ENCARGADO, DUI normalizado, rol del cliente ignorado
    public function test_registro_crea_encargado_pendiente_y_envia_activacion(): void
    {
        Notification::fake();

        $respuesta = $this->postJson('/api/auth/register', $this->datosRegistro(['rol' => 'DIRECTOR', 'role' => 'DIRECTOR', 'estado' => 'activo']));

        $respuesta->assertCreated()->assertJsonMissingPath('access_token');

        $user = User::where('email', 'maria@correo.com')->firstOrFail();
        $this->assertSame('00016297-5', $user->dui);
        $this->assertSame(User::ESTADO_PENDIENTE, $user->estado);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(['ENCARGADO'], $user->getRoleNames()->all());
        $this->assertTrue(Hash::check('Segura123', $user->password));

        Notification::assertSentTo($user, ActivarCuenta::class, function (ActivarCuenta $n) {
            return str_starts_with($n->url, 'http://localhost:5173/activar?enlace=');
        });
    }

    // 2. DUI inválido, duplicado (con y sin guion) y correo duplicado
    public function test_registro_rechaza_dui_invalido_y_duplicados(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->datosRegistro(['dui' => '00016297-4']))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['dui']]);

        $this->postJson('/api/auth/register', $this->datosRegistro())->assertCreated();

        $this->postJson('/api/auth/register', $this->datosRegistro(['dui' => '00016297-5', 'email' => 'otra@correo.com']))
            ->assertStatus(422)->assertJsonValidationErrors('dui');

        $this->postJson('/api/auth/register', $this->datosRegistro(['dui' => '00000001-8', 'email' => 'MARIA@correo.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/register', $this->datosRegistro(['dui' => '00000002-6', 'email' => 'x@correo.com', 'password_confirmation' => 'Otra1234']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(1, User::count());
    }

    // 3. Inicio de sesión: credenciales, cuenta pendiente, cuenta inactiva
    public function test_login_segun_estado_de_la_cuenta(): void
    {
        $activo = $this->usuario(User::ROL_ENCARGADO, ['email' => 'activo@siga.test']);
        $this->usuario(User::ROL_ENCARGADO, ['email' => 'pendiente@siga.test'], User::ESTADO_PENDIENTE);
        $this->usuario(User::ROL_ENCARGADO, ['email' => 'inactivo@siga.test'], User::ESTADO_INACTIVO);

        $this->postJson('/api/auth/login', ['email' => 'activo@siga.test', 'password' => 'incorrecta'])
            ->assertStatus(401)->assertJson(['message' => 'Credenciales inválidas']);

        $this->postJson('/api/auth/login', ['email' => 'noexiste@siga.test', 'password' => 'password'])
            ->assertStatus(401);

        $this->postJson('/api/auth/login', ['email' => 'pendiente@siga.test', 'password' => 'password'])
            ->assertStatus(403)->assertJson(['codigo' => 'cuenta_pendiente']);

        $this->postJson('/api/auth/login', ['email' => 'inactivo@siga.test', 'password' => 'password'])
            ->assertStatus(403)->assertJson(['codigo' => 'cuenta_inactiva']);

        $this->postJson('/api/auth/login', ['email' => ''])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['email', 'password']]);

        $respuesta = $this->postJson('/api/auth/login', ['email' => ' ACTIVO@siga.test', 'password' => 'password']);

        $respuesta->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user' => ['id', 'nombre', 'dui', 'email', 'estado', 'rol']])
            ->assertJsonPath('user.rol', 'ENCARGADO')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonMissingPath('user.password');

        $this->assertStringNotContainsString($activo->password, $respuesta->getContent());
    }

    // 4. me, refresh y logout con un token real
    public function test_me_refresh_y_logout(): void
    {
        $user = $this->usuario(User::ROL_DOCENTE);
        $token = $this->tokenDe($user);

        $this->conToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonPath('user.rol', 'DOCENTE')
            ->assertJsonMissingPath('user.password');

        $nuevo = $this->conToken($token)->postJson('/api/auth/refresh')->assertOk()->json('access_token');
        $this->assertNotSame($token, $nuevo);

        // El token anterior queda invalidado
        $this->conToken($token)->getJson('/api/auth/me')->assertStatus(401);
        $this->conToken($nuevo)->getJson('/api/auth/me')->assertOk();

        $this->conToken($nuevo)->postJson('/api/auth/logout')->assertOk();
        $this->conToken($nuevo)->getJson('/api/auth/me')->assertStatus(401);
    }

    // 5. 401 en JSON sin token o con token alterado
    public function test_respuestas_401_en_json(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonStructure(['message']);
        $this->get('/api/auth/me')->assertStatus(401)->assertJsonStructure(['message']);
        $this->conToken('token.falso.123')->getJson('/api/auth/me')->assertStatus(401);
        $this->conToken('x')->postJson('/api/auth/refresh')->assertStatus(401);
    }

    // 6. Una cuenta desactivada no puede seguir usando un token emitido antes
    public function test_token_de_cuenta_desactivada_queda_bloqueado(): void
    {
        $user = $this->usuario(User::ROL_ENCARGADO);
        $token = $this->tokenDe($user);

        $user->forceFill(['estado' => User::ESTADO_INACTIVO])->save();

        $this->conToken($token)->getJson('/api/auth/me')
            ->assertStatus(403)->assertJson(['codigo' => 'cuenta_inactiva']);
        $this->conToken($token)->postJson('/api/auth/refresh')->assertStatus(403);
    }

    // 7. Activación con enlace firmado: válido, alterado, vencido y de otro correo
    public function test_activacion_con_enlace_firmado(): void
    {
        $user = $this->usuario(User::ROL_ENCARGADO, [], User::ESTADO_PENDIENTE);
        $servicio = app(UsuarioService::class);

        $url = $servicio->urlActivacion($user);

        $this->getJson($url.'x')->assertStatus(403);

        $vencida = URL::temporarySignedRoute('auth.activar', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->getJson($vencida)->assertStatus(403)->assertJson(['message' => 'El enlace no es válido o ha expirado.']);

        $otroHash = URL::temporarySignedRoute('auth.activar', now()->addHour(), ['id' => $user->id, 'hash' => sha1('otro@correo.com')]);
        $this->getJson($otroHash)->assertStatus(403);

        $this->assertSame(User::ESTADO_PENDIENTE, $user->fresh()->estado);

        $this->getJson($url)->assertOk()->assertJson(['message' => 'Cuenta activada. Ya puede iniciar sesión.']);
        $this->assertSame(User::ESTADO_ACTIVO, $user->fresh()->estado);
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->getJson($url)->assertOk()->assertJson(['message' => 'La cuenta ya estaba activada. Puede iniciar sesión.']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    // 8. Reenvío de activación y olvido de contraseña no revelan si el correo existe
    public function test_reenvio_y_olvido_no_revelan_cuentas(): void
    {
        Notification::fake();
        $pendiente = $this->usuario(User::ROL_ENCARGADO, [], User::ESTADO_PENDIENTE);
        $activo = $this->usuario(User::ROL_ENCARGADO);

        $a = $this->postJson('/api/auth/reenviar-activacion', ['email' => $pendiente->email])->assertOk()->json('message');
        $b = $this->postJson('/api/auth/reenviar-activacion', ['email' => 'nadie@siga.test'])->assertOk()->json('message');
        $c = $this->postJson('/api/auth/reenviar-activacion', ['email' => $activo->email])->assertOk()->json('message');
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        Notification::assertSentToTimes($pendiente, ActivarCuenta::class, 1);
        Notification::assertNotSentTo($activo, ActivarCuenta::class);

        $d = $this->postJson('/api/auth/olvide-contrasena', ['email' => $activo->email])->assertOk()->json('message');
        $e = $this->postJson('/api/auth/olvide-contrasena', ['email' => 'nadie@siga.test'])->assertOk()->json('message');
        $this->assertSame($d, $e);
        Notification::assertSentTo($activo, RestablecerContrasena::class);
    }

    // 9. Restablecimiento: token válido una sola vez; token inválido rechazado
    public function test_restablecer_contrasena_token_de_un_solo_uso(): void
    {
        Notification::fake();
        $user = $this->usuario(User::ROL_DOCENTE);

        $this->postJson('/api/auth/olvide-contrasena', ['email' => $user->email])->assertOk();

        $token = null;
        Notification::assertSentTo($user, RestablecerContrasena::class, function (RestablecerContrasena $n) use (&$token) {
            parse_str(parse_url($n->url, PHP_URL_QUERY), $query);
            $token = $query['token'];

            return str_starts_with($n->url, 'http://localhost:5173/restablecer-contrasena?');
        });

        $datos = ['token' => $token, 'email' => $user->email, 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234'];

        $this->postJson('/api/auth/restablecer-contrasena', array_merge($datos, ['token' => 'invalido']))
            ->assertStatus(422)->assertJsonValidationErrors('token');

        $this->postJson('/api/auth/restablecer-contrasena', $datos)->assertOk();
        $this->assertTrue(Hash::check('Nueva1234', $user->fresh()->password));

        $this->postJson('/api/auth/restablecer-contrasena', array_merge($datos, ['password' => 'Otra12345', 'password_confirmation' => 'Otra12345']))
            ->assertStatus(422)->assertJsonValidationErrors('token');

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Nueva1234'])->assertOk();
    }

    // 10. Cambio de contraseña autenticado
    public function test_cambio_de_contrasena(): void
    {
        $user = $this->usuario(User::ROL_ENCARGADO);

        $this->como($user)->putJson('/api/auth/contrasena', ['actual' => 'mala', 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234'])
            ->assertStatus(422)->assertJsonValidationErrors('actual');

        $this->como($user)->putJson('/api/auth/contrasena', ['actual' => 'password', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->como($user)->putJson('/api/auth/contrasena', ['actual' => 'password', 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234'])
            ->assertOk();

        $this->assertTrue(Hash::check('Nueva1234', $user->fresh()->password));
    }

    // 11. Límite de intentos en endpoints públicos
    public function test_limite_de_intentos_de_login(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'x@siga.test', 'password' => 'mala'])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => 'x@siga.test', 'password' => 'mala'])
            ->assertStatus(429)->assertJsonStructure(['message']);
    }

    // 12. CORS: el preflight del frontend se acepta con Authorization
    public function test_preflight_cors_del_frontend(): void
    {
        $this->call('OPTIONS', '/api/auth/me', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}

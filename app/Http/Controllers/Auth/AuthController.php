<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\Dui;
use App\Services\UsuarioService;
use App\Support\DuiFormato;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as ReglaPassword;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected UsuarioService $usuarioService;

    // Respuesta idéntica exista o no la cuenta, para no revelar correos registrados
    private const MENSAJE_GENERICO_CORREO = 'Si el correo corresponde a una cuenta, recibirá un mensaje con las instrucciones.';

    public function __construct(UsuarioService $usuarioService)
    {
        $this->usuarioService = $usuarioService;
    }

    public function login(Request $request)
    {
        try {
            $request->merge(['email' => $this->normalizarEmail($request->email)]);

            $credenciales = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $credenciales['email'])->first();

            if (! $user || ! Hash::check($credenciales['password'], $user->password)) {
                return response()->json([
                    'message' => 'Credenciales inválidas',
                ], 401);
            }

            if ($user->estado === User::ESTADO_PENDIENTE) {
                return response()->json([
                    'message' => 'Su cuenta aún no está activada. Use el enlace que enviamos a su correo o solicite uno nuevo.',
                    'codigo' => 'cuenta_pendiente',
                ], 403);
            }

            if ($user->estado !== User::ESTADO_ACTIVO) {
                return response()->json([
                    'message' => 'Su cuenta está desactivada. Comuníquese con la dirección del centro escolar.',
                    'codigo' => 'cuenta_inactiva',
                ], 403);
            }

            $token = auth('api')->login($user);

            return $this->responseWithToken($token, $user);

        } catch (ValidationException $e) {
            return $this->errorValidacion($e);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al iniciar sesión',
            ], 500);
        }
    }

    /**
     * Registro público del encargado. El rol es siempre ENCARGADO: cualquier
     * campo rol/role que envíe el cliente se ignora.
     */
    public function register(Request $request)
    {
        try {
            $request->merge([
                'dui' => DuiFormato::normalizar($request->dui),
                'email' => $this->normalizarEmail($request->email),
            ]);

            $validated = $request->validate([
                'nombre' => 'required|string|max:80',
                'dui' => ['required', 'string', new Dui, 'unique:users,dui'],
                'email' => 'required|string|email|max:100|unique:users,email',
                'password' => ['required', 'string', 'confirmed', ReglaPassword::min(8)->letters()->numbers()],
            ], [
                'nombre.required' => 'El nombre es obligatorio.',
                'dui.required' => 'El DUI es obligatorio.',
                'dui.unique' => 'Este DUI o correo ya tiene una cuenta.',
                'email.required' => 'El correo es obligatorio.',
                'email.email' => 'El correo no tiene un formato válido.',
                'email.unique' => 'Este DUI o correo ya tiene una cuenta.',
                'password.required' => 'La contraseña es obligatoria.',
                'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            ]);

            $this->usuarioService->registrarEncargado($validated);

            return response()->json([
                'message' => 'Cuenta creada. Revise su correo para activarla antes de iniciar sesión.',
            ], 201);

        } catch (ValidationException $e) {
            return $this->errorValidacion($e);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al registrar la cuenta',
            ], 500);
        }
    }

    /**
     * Enlace firmado y temporal (middleware signed). Valida además que el
     * hash corresponda al correo actual de la cuenta.
     */
    public function activar(Request $request, string $id, string $hash)
    {
        try {
            $user = User::find($id);

            if (! $user || ! hash_equals(sha1($user->email), $hash)) {
                return response()->json([
                    'message' => 'El enlace de activación no es válido.',
                ], 403);
            }

            $resultado = $this->usuarioService->activar($user);

            if ($resultado === 'inactiva') {
                return response()->json([
                    'message' => 'La cuenta está desactivada. Comuníquese con la dirección del centro escolar.',
                    'codigo' => 'cuenta_inactiva',
                ], 403);
            }

            return response()->json([
                'message' => $resultado === 'activada'
                    ? 'Cuenta activada. Ya puede iniciar sesión.'
                    : 'La cuenta ya estaba activada. Puede iniciar sesión.',
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al activar la cuenta',
            ], 500);
        }
    }

    public function reenviarActivacion(Request $request)
    {
        try {
            $request->merge(['email' => $this->normalizarEmail($request->email)]);
            $validated = $request->validate(['email' => 'required|string|email']);

            $user = User::where('email', $validated['email'])->first();

            if ($user && $user->estado === User::ESTADO_PENDIENTE) {
                $this->usuarioService->enviarActivacion($user);
            }

            return response()->json(['message' => self::MENSAJE_GENERICO_CORREO]);

        } catch (ValidationException $e) {
            return $this->errorValidacion($e);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al reenviar el enlace',
            ], 500);
        }
    }

    public function olvideContrasena(Request $request)
    {
        try {
            $request->merge(['email' => $this->normalizarEmail($request->email)]);
            $validated = $request->validate(['email' => 'required|string|email']);

            // El broker de Laravel guarda el token (hasheado) en password_reset_tokens
            // y limita los reenvíos; su resultado no se comunica al cliente.
            Password::sendResetLink(['email' => $validated['email']]);

            return response()->json(['message' => self::MENSAJE_GENERICO_CORREO]);

        } catch (ValidationException $e) {
            return $this->errorValidacion($e);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al procesar la solicitud',
            ], 500);
        }
    }

    public function restablecerContrasena(Request $request)
    {
        try {
            $request->merge(['email' => $this->normalizarEmail($request->email)]);

            $validated = $request->validate([
                'token' => 'required|string',
                'email' => 'required|string|email',
                'password' => ['required', 'string', 'confirmed', ReglaPassword::min(8)->letters()->numbers()],
            ], [
                'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            ]);

            // Password::reset valida token y vencimiento y lo elimina al usarlo
            $estado = Password::reset($validated, function (User $user, string $password) {
                $user->password = Hash::make($password);
                $user->save();

                $this->usuarioService->despuesDeRestablecer($user);

                event(new PasswordReset($user));
            });

            if ($estado !== Password::PASSWORD_RESET) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => ['token' => ['El enlace no es válido o ha expirado. Solicite uno nuevo.']],
                ], 422);
            }

            return response()->json([
                'message' => 'Contraseña actualizada. Ya puede iniciar sesión.',
            ]);

        } catch (ValidationException $e) {
            return $this->errorValidacion($e);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al restablecer la contraseña',
            ], 500);
        }
    }

    public function cambiarContrasena(Request $request)
    {
        try {
            $validated = $request->validate([
                'actual' => 'required|string|current_password:api',
                'password' => ['required', 'string', 'confirmed', 'different:actual', ReglaPassword::min(8)->letters()->numbers()],
            ], [
                'actual.current_password' => 'La contraseña actual no es correcta.',
                'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
                'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            ]);

            $user = auth('api')->user();
            $user->password = Hash::make($validated['password']);
            $user->save();

            // Las demás sesiones quedan revocadas por la nueva huella; esta
            // recibe un token nuevo y el anterior pasa a la lista negra.
            auth('api')->invalidate();
            $token = auth('api')->login($user);

            return $this->responseWithToken($token, $user, 'Contraseña actualizada correctamente');

        } catch (ValidationException $e) {
            return $this->errorValidacion($e);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al cambiar la contraseña',
            ], 500);
        }
    }

    public function me()
    {
        return response()->json([
            'user' => auth('api')->user()->datosSesion(),
        ]);
    }

    // Invalida el token actual (lista negra de JWT)
    public function logout()
    {
        try {
            auth('api')->logout();
        } catch (\Exception $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    // Solo renueva un token todavía vigente (la ruta está bajo auth:api).
    // El token anterior queda invalidado.
    public function refresh()
    {
        try {
            $user = auth('api')->user();
            // El token renovado conserva la huella de la contraseña vigente
            $token = auth('api')->claims($user->getJWTCustomClaims())->refresh();

            return $this->responseWithToken($token, $user);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo renovar la sesión',
            ], 401);
        }
    }

    protected function responseWithToken(string $token, User $user, ?string $mensaje = null)
    {
        return response()->json(array_filter([
            'message' => $mensaje,
        ]) + [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user->datosSesion(),
        ]);
    }

    private function errorValidacion(ValidationException $e)
    {
        return response()->json([
            'message' => 'Error de validación',
            'errors' => $e->errors(),
        ], 422);
    }

    private function normalizarEmail($email)
    {
        return is_string($email) ? mb_strtolower(trim($email)) : $email;
    }
}

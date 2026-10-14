<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as ReglaPassword;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected UsuarioService $usuarioService;

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
     * Registrarse (diagrama): cuenta pública del encargado. El rol es siempre
     * ENCARGADO: cualquier campo rol/role que envíe el cliente se ignora.
     */
    public function register(Request $request)
    {
        try {
            $request->merge(['email' => $this->normalizarEmail($request->email)]);

            $validated = $request->validate([
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => ['required', 'string', 'confirmed', ReglaPassword::min(8)->letters()->numbers()],
            ], [
                'email.required' => 'El correo es obligatorio.',
                'email.email' => 'El correo no tiene un formato válido.',
                'email.unique' => 'Este correo ya tiene una cuenta.',
                'password.required' => 'La contraseña es obligatoria.',
                'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            ]);

            $this->usuarioService->registrarEncargado($validated);

            return response()->json([
                'message' => 'Cuenta creada. Ya puede iniciar sesión.',
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

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as ReglaPassword;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de cuentas por el director (rutas bajo role:DIRECTOR).
 */
class UsuarioController extends Controller
{
    protected UsuarioService $usuarioService;

    public function __construct(UsuarioService $usuarioService)
    {
        $this->usuarioService = $usuarioService;
    }

    public function index(Request $request)
    {
        try {
            $request->validate([
                'buscar' => 'nullable|string|max:100',
                'rol' => ['nullable', Rule::in(User::ROLES)],
            ]);

            $usuarios = $this->usuarioService->listar($request->buscar, $request->rol);

            return response()->json([
                'usuarios' => $usuarios,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener los usuarios',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $user = User::findOrFail($id);

            return response()->json([
                'usuario' => $user->datosSesion(),
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 404);
        }
    }

    /**
     * Crea la cuenta con correo, contraseña inicial y rol (asignarRol). La
     * persona puede cambiar su contraseña después (cambiarContrasena).
     */
    public function store(Request $request)
    {
        try {
            $request->merge([
                'email' => is_string($request->email) ? mb_strtolower(trim($request->email)) : $request->email,
            ]);

            $validated = $request->validate([
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => ['required', 'string', ReglaPassword::min(8)->letters()->numbers()],
                'rol' => ['required', Rule::in(User::ROLES)],
            ], [
                'email.unique' => 'Este correo ya tiene una cuenta.',
                'rol.in' => 'El rol seleccionado no es válido.',
            ]);

            $user = $this->usuarioService->crearCuenta($validated, $validated['rol']);

            return response()->json([
                'message' => 'Usuario creado exitosamente',
                'usuario' => $user->datosSesion(),
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear el usuario',
            ], 500);
        }
    }

    /**
     * Correo y rol (asignarRol).
     */
    public function update(Request $request, string $id)
    {
        try {
            $user = User::findOrFail($id);

            $request->merge([
                'email' => is_string($request->email) ? mb_strtolower(trim($request->email)) : $request->email,
            ]);

            $validated = $request->validate([
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'rol' => ['required', Rule::in(User::ROLES)],
            ], [
                'email.unique' => 'Este correo ya tiene una cuenta.',
                'rol.in' => 'El rol seleccionado no es válido.',
            ]);

            $user = $this->usuarioService->actualizar($user, $validated);

            return response()->json([
                'message' => 'Usuario actualizado exitosamente',
                'usuario' => $user->datosSesion(),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 404);

        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar el usuario',
            ], 500);
        }
    }

    /**
     * Eliminación física solo sin información asociada (tieneRegistrosAsociados).
     */
    public function destroy(Request $request, string $id)
    {
        try {
            $user = User::findOrFail($id);

            $this->usuarioService->eliminar($user, $request->user());

            return response()->json([
                'message' => 'Usuario eliminado exitosamente',
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 404);

        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar el usuario',
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\Dui;
use App\Services\UsuarioService;
use App\Support\DuiFormato;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                'estado' => 'nullable|in:pendiente,activo,inactivo',
            ]);

            $usuarios = $this->usuarioService->listar(
                $request->buscar,
                $request->rol,
                $request->estado
            );

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
     * Crea la cuenta pendiente y envía el enlace para definir la contraseña.
     * El director no define ni ve contraseñas.
     */
    public function store(Request $request)
    {
        try {
            $request->merge([
                'dui' => DuiFormato::normalizar($request->dui),
                'email' => is_string($request->email) ? mb_strtolower(trim($request->email)) : $request->email,
            ]);

            $validated = $request->validate([
                'nombre' => 'required|string|max:80',
                'dui' => ['required', 'string', new Dui, 'unique:users,dui'],
                'email' => 'required|string|email|max:100|unique:users,email',
                'rol' => ['required', Rule::in(User::ROLES)],
            ], [
                'dui.unique' => 'Este DUI ya tiene una cuenta.',
                'email.unique' => 'Este correo ya tiene una cuenta.',
                'rol.in' => 'El rol seleccionado no es válido.',
            ]);

            $user = $this->usuarioService->crearCuenta($validated, $validated['rol']);

            return response()->json([
                'message' => 'Usuario creado. Se envió un enlace a su correo para definir la contraseña.',
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
     * Nombre, correo y rol. El DUI no es editable; solo se puede completar
     * una vez en cuentas heredadas que no lo tienen (siga:usuarios-sin-dui).
     */
    public function update(Request $request, string $id)
    {
        try {
            $user = User::findOrFail($id);

            $request->merge([
                'email' => is_string($request->email) ? mb_strtolower(trim($request->email)) : $request->email,
            ]);

            $completarDui = $user->dui === null;
            if ($completarDui && $request->filled('dui')) {
                $request->merge(['dui' => DuiFormato::normalizar($request->dui)]);
            }

            $validated = $request->validate([
                'nombre' => 'required|string|max:80',
                'email' => ['required', 'string', 'email', 'max:100', Rule::unique('users', 'email')->ignore($user->id)],
                'rol' => ['required', Rule::in(User::ROLES)],
                'dui' => $completarDui ? ['nullable', 'string', new Dui, 'unique:users,dui'] : 'prohibited',
            ], [
                'email.unique' => 'Este correo ya tiene una cuenta.',
                'rol.in' => 'El rol seleccionado no es válido.',
                'dui.prohibited' => 'El DUI no puede modificarse.',
                'dui.unique' => 'Este DUI ya pertenece a otra cuenta.',
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

    public function cambiarEstado(Request $request, string $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'estado' => 'required|in:activo,inactivo',
            ]);

            $user = $this->usuarioService->cambiarEstado($user, $validated['estado'], $request->user());

            return response()->json([
                'message' => $validated['estado'] === User::ESTADO_ACTIVO ? 'Usuario activado' : 'Usuario desactivado',
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
                'message' => 'Error al cambiar el estado del usuario',
            ], 500);
        }
    }

    // Para cuentas pendientes: nuevo enlace para definir la contraseña
    public function reenviarEnlace(string $id)
    {
        try {
            $user = User::findOrFail($id);

            if ($user->estado !== User::ESTADO_PENDIENTE) {
                return response()->json([
                    'message' => 'Solo se reenvía el enlace a cuentas pendientes.',
                ], 409);
            }

            $this->usuarioService->enviarEnlaceContrasena($user, true);

            return response()->json([
                'message' => 'Enlace reenviado al correo del usuario.',
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al reenviar el enlace',
            ], 500);
        }
    }

    /**
     * Eliminación física solo sin información asociada; si la tiene, se
     * debe desactivar (integridad histórica).
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

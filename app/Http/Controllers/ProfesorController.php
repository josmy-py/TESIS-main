<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Services\ProfesorService;
use App\Services\UsuarioService;
use App\Rules\Dui;
use App\Support\DuiFormato;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ProfesorController extends Controller
{
    protected ProfesorService $profesorService;

    protected UsuarioService $usuarioService;

    public function __construct(ProfesorService $profesorService, UsuarioService $usuarioService)
    {
        $this->profesorService = $profesorService;
        $this->usuarioService = $usuarioService;
    }

    // La cuenta vinculada debe ser DOCENTE y del mismo DUI que el perfil
    private function verificarCuenta(array $data): void
    {
        $this->usuarioService->verificarCorrespondencia(
            User::findOrFail($data['user_id']),
            $data['dui'],
            User::ROL_DOCENTE
        );
    }

    public function index()
    {
        try {
            $profesores = $this->profesorService->listar();

            return response()->json([
                'profesores' => $profesores
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener los profesores',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->merge(['dui' => DuiFormato::normalizar($request->dui)]);

            $data = $request->validate([
                'nombre' => 'required|string|max:80',
                'dui' => ['required', 'string', 'max:10', 'unique:profesores,dui', new Dui],
                'fecha_nacimiento' => 'required|date',
                'codigo' => 'required|string|max:15|unique:profesores,codigo',
                'telefono' => 'required|string|max:10|unique:profesores,telefono|regex:/^[267][0-9]{3}-?[0-9]{4}$/',
                'direccion' => 'required|string|max:100',
                'user_id' => 'required|exists:users,id|unique:profesores,user_id',
            ], [
                'nombre.required' => 'El nombre del profesor es obligatorio.',
                'dui.required' => 'El DUI del profesor es obligatorio.',
                'dui.unique' => 'El DUI del profesor ya está registrado.',
                'codigo.unique' => 'El código del profesor ya está registrado.',
                'telefono.unique' => 'El teléfono del profesor ya está registrado.',
                'direccion.required' => 'La dirección del profesor es obligatoria.',
                'user_id.unique' => 'El usuario ya está asignado a otro profesor.',
            ]);

            $this->verificarCuenta($data);

            $profesor = $this->profesorService->crear($data);

            return response()->json([
                'message' => 'Profesor registrado exitosamente',
                'profesor' => $profesor
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\DomainException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => ['user_id' => [$e->getMessage()]],
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear el profesor',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $profesor = $this->profesorService->obtener($id);

            return response()->json($profesor);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: profesor no encontrado',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $request->merge(['dui' => DuiFormato::normalizar($request->dui)]);

            $data = $request->validate([
                'nombre' => 'required|string|max:80',
                'dui' => ['required', 'string', 'max:10', 'unique:profesores,dui,' . $id, new Dui],
                'fecha_nacimiento' => 'required|date',
                'codigo' => 'required|string|max:15|unique:profesores,codigo,' . $id,
                'telefono' => 'required|string|max:10|unique:profesores,telefono,' . $id . '|regex:/^[267][0-9]{3}-?[0-9]{4}$/',
                'direccion' => 'required|string|max:100',
                'user_id' => 'required|exists:users,id|unique:profesores,user_id,' . $id,
            ], [
                'nombre.required' => 'El nombre del profesor es obligatorio.',
                'nombre.max' => 'El nombre del profesor no debe exceder los 80 caracteres.',
                'dui.required' => 'El DUI del profesor es obligatorio.',
                'dui.unique' => 'El DUI del profesor ya está registrado.',
                'fecha_nacimiento.required' => 'La fecha de nacimiento del profesor es obligatoria.',
                'codigo.max' => 'El código del profesor no debe exceder los 15 caracteres.',
                'codigo.unique' => 'El código del profesor ya está registrado.',
                'telefono.unique' => 'El teléfono del profesor ya está registrado.',
                'direccion.required' => 'La dirección del profesor es obligatoria.',
                'user_id.unique' => 'El usuario ya está asignado a otro profesor.',
            ]);

            $this->verificarCuenta($data);

            $profesor = $this->profesorService->actualizar($id, $data);

            return response()->json([
                'message' => 'Profesor actualizado exitosamente',
                'profesor' => $profesor
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: profesor no encontrado',
            ], 404);

        } catch (\DomainException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => ['user_id' => [$e->getMessage()]],
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar el profesor',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $eliminado = $this->profesorService->eliminar($id);

            if (!$eliminado) {
                return response()->json([
                    'message' => 'No se puede eliminar el profesor porque tiene secciones o asignaciones asociadas.'
                ], 400);
            }

            return response()->json([
                'message' => 'Profesor eliminado exitosamente'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: profesor no encontrado',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar el profesor',
            ], 500);
        }
    }
}

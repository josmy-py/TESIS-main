<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Profesor;
use App\Services\ProfesorService;
use App\Rules\Dui;
use App\Support\DuiFormato;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfesorController extends Controller
{
    private const MENSAJES = [
        'nombre.required' => 'El nombre del profesor es obligatorio.',
        'nombre.max' => 'El nombre del profesor no debe exceder los 80 caracteres.',
        'dui.required' => 'El DUI del profesor es obligatorio.',
        'dui.unique' => 'El DUI del profesor ya está registrado.',
        'user_id.required' => 'Seleccione la cuenta de usuario del profesor.',
        'user_id.integer' => 'Seleccione una cuenta de usuario válida.',
        'fecha_nacimiento.required' => 'La fecha de nacimiento del profesor es obligatoria.',
        'fecha_nacimiento.before_or_equal' => 'El profesor debe ser mayor de edad.',
        'telefono.required' => 'El teléfono del profesor es obligatorio.',
        'telefono.unique' => 'El teléfono del profesor ya está registrado.',
        'telefono.regex' => 'El teléfono debe tener 8 dígitos y empezar con 2, 6 o 7 (por ejemplo 7123-4567).',
        'direccion.required' => 'La dirección del profesor es obligatoria.',
    ];

    protected ProfesorService $profesorService;

    public function __construct(ProfesorService $profesorService)
    {
        $this->profesorService = $profesorService;
    }

    // Campos que el director puede escribir al crear y al editar
    private function reglasComunes(?int $id = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80'],
            'fecha_nacimiento' => 'required|date|before_or_equal:-18 years',
            'telefono' => ['required', 'string', 'max:15', 'regex:/^[267][0-9]{3}-?[0-9]{4}$/', Rule::unique('profesores', 'telefono')->ignore($id)],
            'direccion' => 'required|string|max:100',
        ];
    }

    private function errorValidacion(array $errores)
    {
        return response()->json([
            'message' => 'Error de validación',
            'errors' => $errores,
        ], 422);
    }

    public function index(Request $request)
    {
        try {
            $profesores = $this->profesorService->listar($request->query('buscar'));

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

    /** Cuentas DOCENTE sin profesor, para el campo de cuenta del formulario. */
    public function cuentasDisponibles()
    {
        return response()->json(['cuentas' => $this->profesorService->cuentasDisponibles()]);
    }

    /**
     * Registra al docente y lo vincula con una cuenta DOCENTE ya creada en
     * Usuarios (sin correo ni contraseña en este formulario). El código se
     * genera solo.
     */
    public function store(Request $request)
    {
        try {
            $request->merge([
                'dui' => DuiFormato::normalizar($request->dui),
            ]);

            $data = $request->validate(array_merge($this->reglasComunes(), [
                'dui' => ['required', 'string', 'max:10', 'unique:profesores,dui', new Dui],
                'user_id' => 'required|integer',
            ]), self::MENSAJES);

            $profesor = $this->profesorService->crear($data);

            return response()->json([
                'message' => "Docente registrado con el código {$profesor->codigo}.",
                'profesor' => $profesor
            ], 201);

        } catch (ValidationException $e) {
            return $this->errorValidacion($e->errors());

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
            return response()->json([
                'profesor' => $this->profesorService->obtener($id),
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: profesor no encontrado',
            ], 404);
        }
    }

    /**
     * Nombre, fecha de nacimiento, teléfono, dirección y cuenta vinculada. El
     * código y el DUI no se modifican.
     */
    public function update(Request $request, string $id)
    {
        try {
            $profesor = Profesor::findOrFail($id);

            $inmutables = [];
            if ($request->filled('codigo') && $request->codigo !== $profesor->codigo) {
                $inmutables['codigo'] = ['El código del profesor no se puede modificar.'];
            }
            if ($request->filled('dui') && DuiFormato::normalizar($request->dui) !== $profesor->dui) {
                $inmutables['dui'] = ['El DUI del profesor no se puede modificar.'];
            }
            if ($inmutables) {
                return $this->errorValidacion($inmutables);
            }

            $data = $request->validate(array_merge($this->reglasComunes($profesor->id), [
                'user_id' => 'nullable|integer',
            ]), self::MENSAJES);

            $profesor = $this->profesorService->actualizar($profesor->id, $data);

            return response()->json([
                'message' => 'Profesor actualizado exitosamente',
                'profesor' => $profesor
            ], 200);

        } catch (ValidationException $e) {
            return $this->errorValidacion($e->errors());

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: profesor no encontrado',
            ], 404);

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
                ], 409);
            }

            return response()->json([
                'message' => 'Profesor eliminado exitosamente. Su cuenta de usuario sigue en Usuarios.'
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

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
        'nombre.max' => 'El nombre del profesor no debe exceder los 50 caracteres.',
        'nombre.unique' => 'Ya existe un profesor registrado con ese nombre.',
        'dui.required' => 'El DUI del profesor es obligatorio.',
        'dui.unique' => 'El DUI del profesor ya está registrado.',
        'email.required' => 'El correo es obligatorio para crear la cuenta del docente.',
        'email.email' => 'El correo no es válido.',
        'fecha_nacimiento.required' => 'La fecha de nacimiento del profesor es obligatoria.',
        'fecha_nacimiento.before_or_equal' => 'El profesor debe ser mayor de edad.',
        'telefono.required' => 'El teléfono del profesor es obligatorio.',
        'telefono.unique' => 'El teléfono del profesor ya está registrado.',
        'telefono.regex' => 'El teléfono debe tener 8 dígitos y empezar con 2, 6 o 7 (por ejemplo 7123-4567).',
        'direccion.required' => 'La dirección del profesor es obligatoria.',
        'especialidad.required' => 'La especialidad del profesor es obligatoria.',
        'especialidad.max' => 'La especialidad no debe exceder los 50 caracteres.',
        'fecha_contratacion.required' => 'La fecha de contratación es obligatoria.',
        'fecha_contratacion.before_or_equal' => 'La fecha de contratación no puede ser futura.',
        'fecha_contratacion.after' => 'La fecha de contratación debe ser posterior a la fecha de nacimiento.',
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
            'nombre' => ['required', 'string', 'max:50', Rule::unique('profesores', 'nombre')->ignore($id)],
            'fecha_nacimiento' => 'required|date|before_or_equal:-18 years',
            'telefono' => ['required', 'string', 'max:10', 'regex:/^[267][0-9]{3}-?[0-9]{4}$/', Rule::unique('profesores', 'telefono')->ignore($id)],
            'direccion' => 'required|string|max:100',
            'especialidad' => 'required|string|max:50',
            'fecha_contratacion' => 'required|date|after:fecha_nacimiento|before_or_equal:today',
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

    /**
     * Registra al docente y crea su cuenta DOCENTE; la persona recibe un
     * enlace en su correo para definir la contraseña. El código se genera solo.
     */
    public function store(Request $request)
    {
        try {
            $request->merge([
                'dui' => DuiFormato::normalizar($request->dui),
                'email' => is_string($request->email) ? mb_strtolower(trim($request->email)) : $request->email,
            ]);

            $data = $request->validate(array_merge($this->reglasComunes(), [
                'dui' => ['required', 'string', 'max:10', 'unique:profesores,dui', new Dui],
                'email' => 'required|string|email|max:100',
            ]), self::MENSAJES);

            $profesor = $this->profesorService->crear($data);

            return response()->json([
                'message' => "Docente registrado con el código {$profesor->codigo}. Se envió un enlace a su correo para definir la contraseña.",
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
     * Nombre, fecha de nacimiento, teléfono, dirección, especialidad y fecha
     * de contratación. El código, el DUI y la cuenta no se modifican.
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
            if ($request->filled('user_id') && (int) $request->user_id !== (int) $profesor->user_id) {
                $inmutables['user_id'] = ['La cuenta del profesor no se puede cambiar.'];
            }
            if ($inmutables) {
                return $this->errorValidacion($inmutables);
            }

            $data = $request->validate($this->reglasComunes($profesor->id), self::MENSAJES);

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
                    'message' => 'No se puede eliminar el profesor porque tiene secciones o asignaciones asociadas. Desactive su cuenta en su lugar.'
                ], 409);
            }

            return response()->json([
                'message' => 'Profesor y su cuenta eliminados exitosamente'
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

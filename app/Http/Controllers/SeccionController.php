<?php

namespace App\Http\Controllers;

use App\Models\Seccion;
use App\Services\SeccionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * M2.4: secciones. 422 = datos inválidos, 409 = conflicto con datos existentes
 * (sección repetida, docente ocupado, matrículas o asignaciones asociadas).
 */
class SeccionController extends Controller
{
    public function __construct(protected SeccionService $seccionService)
    {
    }

    public function index(Request $request)
    {
        try {
            $filtros = $request->validate([
                'anio_lectivo_id' => 'nullable|integer|exists:anios_lectivos,id',
                'grado_id' => 'nullable|integer|exists:grados,id',
                'turno' => ['nullable', Rule::in(Seccion::TURNOS)],
            ], $this->mensajes());

            return response()->json($this->seccionService->listar($filtros), 200);
        } catch (ValidationException $e) {
            return $this->errorValidacion($e);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al obtener las secciones'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $seccion = $this->seccionService->crear($this->validar($request));

            return response()->json([
                'message' => 'Sección registrada exitosamente',
                'seccion' => $seccion,
            ], 201);
        } catch (ValidationException $e) {
            return $this->errorValidacion($e);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al crear la sección'], 500);
        }
    }

    public function show(string $id)
    {
        try {
            return response()->json(['seccion' => $this->seccionService->obtener($id)], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Error: sección no encontrada'], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $seccion = $this->seccionService->actualizar($id, $this->validar($request));

            return response()->json([
                'message' => 'Sección actualizada exitosamente',
                'seccion' => $seccion,
            ], 200);
        } catch (ValidationException $e) {
            return $this->errorValidacion($e);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Error: sección no encontrada'], 404);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al actualizar la sección'], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $this->seccionService->eliminar($id);

            return response()->json(['message' => 'Sección eliminada exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Error: sección no encontrada'], 404);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al eliminar la sección'], 500);
        }
    }

    private function validar(Request $request): array
    {
        $request->merge([
            'nombre' => is_string($request->nombre) ? Seccion::normalizarNombre($request->nombre) : $request->nombre,
            'turno' => is_string($request->turno) ? mb_strtolower(trim($request->turno)) : $request->turno,
        ]);

        return $request->validate([
            'nombre' => 'required|string|max:10',
            'capacidad' => 'required|integer|min:'.Seccion::CAPACIDAD_MINIMA.'|max:'.Seccion::CAPACIDAD_MAXIMA,
            'turno' => ['required', Rule::in(Seccion::TURNOS)],
            'anio_lectivo_id' => 'required|integer',
            'grado_id' => 'required|integer|exists:grados,id',
            'profesor_id' => 'required|integer|exists:profesores,id',
        ], $this->mensajes());
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre de la sección es obligatorio.',
            'nombre.max' => 'El nombre de la sección no debe exceder los 10 caracteres.',
            'capacidad.required' => 'La capacidad de la sección es obligatoria.',
            'capacidad.integer' => 'La capacidad debe ser un número entero.',
            'capacidad.min' => 'La capacidad debe ser de al menos '.Seccion::CAPACIDAD_MINIMA.' estudiante.',
            'capacidad.max' => 'La capacidad no puede ser mayor de '.Seccion::CAPACIDAD_MAXIMA.' estudiantes.',
            'turno.required' => 'El turno de la sección es obligatorio.',
            'turno.in' => 'El turno debe ser matutino o vespertino.',
            'anio_lectivo_id.required' => 'El año lectivo de la sección es obligatorio.',
            'anio_lectivo_id.exists' => 'El año lectivo seleccionado no existe.',
            'grado_id.required' => 'El grado es obligatorio.',
            'grado_id.exists' => 'El grado seleccionado no es válido.',
            'profesor_id.required' => 'El docente responsable es obligatorio.',
            'profesor_id.exists' => 'El docente seleccionado no es válido.',
        ];
    }

    private function errorValidacion(ValidationException $e)
    {
        return response()->json([
            'message' => 'Error de validación',
            'errors' => $e->errors(),
        ], 422);
    }
}

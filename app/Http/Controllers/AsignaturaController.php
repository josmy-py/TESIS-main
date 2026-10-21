<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Support\NombreUnico;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de asignaturas (diagrama ER 21/10/2026: solo nombre, único). Las
 * materias de cada plan se administran con /plan-asignaturas.
 */
class AsignaturaController extends Controller
{
    private const MENSAJES = [
        'nombre.required' => 'El nombre de la asignatura es obligatorio.',
        'nombre.max' => 'El nombre de la asignatura no debe exceder los 50 caracteres.',
        'nombre.unique' => 'La asignatura ya existe.',
    ];

    /** Cada asignatura trae los planes de estudio que la incluyen. */
    public function index()
    {
        try {
            $asignaturas = Asignatura::with('planes:id,nombre,especialidad_id,estado')->orderBy('nombre')->get();

            return response()->json(['asignaturas' => $asignaturas], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al obtener las asignaturas'], 500);
        }
    }

    public function show(string $id)
    {
        try {
            return response()->json(Asignatura::with('planes:id,nombre,especialidad_id,estado')->findOrFail($id));
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Error la asignatura no fue encontrada'], 404);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate(['nombre' => $this->reglasNombre()], self::MENSAJES);
            $asignatura = Asignatura::create(['nombre' => trim($request->nombre)]);

            return response()->json(['message' => 'Asignatura registrada exitosamente', 'asignatura' => $asignatura], 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al crear la asignatura'], 500);
        }
    }

    /** Renombrar cambia el nombre en todos los planes que la incluyen. */
    public function update(Request $request, string $id)
    {
        try {
            $asignatura = Asignatura::findOrFail($id);
            $request->validate(['nombre' => $this->reglasNombre((int) $id)], self::MENSAJES);
            $asignatura->update(['nombre' => trim($request->nombre)]);

            return response()->json(['message' => 'Asignatura actualizada exitosamente', 'asignatura' => $asignatura], 200);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Error la asignatura no fue encontrada'], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al actualizar la asignatura'], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $asignatura = Asignatura::findOrFail($id);
            if ($asignatura->planAsignaturas()->exists()) {
                return response()->json(['message' => 'No se puede eliminar la asignatura porque está en un plan de estudio'], 409);
            }

            $asignatura->delete();

            return response()->json(['message' => 'Asignatura eliminada exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Error la asignatura no fue encontrada'], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Error al eliminar la asignatura'], 500);
        }
    }

    private function reglasNombre(?int $excepto = null): array
    {
        return [
            'required', 'string', 'max:50',
            Rule::unique('asignaturas', 'nombre')->ignore($excepto),
            NombreUnico::regla(Asignatura::when($excepto, fn ($q) => $q->where('id', '<>', $excepto)), 'nombre', self::MENSAJES['nombre.unique']),
        ];
    }
}

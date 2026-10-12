<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Grado;
use App\Support\CatalogoGrados;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class GradoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $grados = Grado::ordenAcademico();
            return response()->json(['grados' => $grados], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener los grados',
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $this->nombreOficial($request);
            $request->validate([
                'nombre' => ['required', 'string', Rule::in(array_keys(CatalogoGrados::GRADOS)), 'unique:grados,nombre'],
            ], $this->mensajes());

            $grado = Grado::create([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Grado registrado exitosamente', 'grado' => $grado], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear el grado',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $grado = Grado::findOrFail($id);
            return response()->json(['grado' => $grado], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: grado no encontrado',
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $grado = Grado::findOrFail($id);

            $this->nombreOficial($request);
            $request->validate([
                'nombre' => ['required', 'string', Rule::in(array_keys(CatalogoGrados::GRADOS)), Rule::unique('grados', 'nombre')->ignore($id)],
            ], $this->mensajes());

            // Cambiar el nombre cambia el orden: no se permite si el grado ya está en uso
            if ($request->nombre !== $grado->nombre && ($grado->secciones()->exists() || $grado->planesEstudio()->exists())) {
                return response()->json([
                    'message' => 'No se puede cambiar el nombre del grado porque ya tiene secciones o planes de estudio: cambiaría su orden en la promoción.',
                ], 409);
            }

            $grado->update([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Grado actualizado exitosamente', 'grado' => $grado], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: grado no encontrado',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar el grado',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $grado = Grado::findOrFail($id);

            if ($grado->secciones()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar el grado porque tiene secciones o asignaciones asociadas'
                ], 409);
            }

            if ($grado->planesEstudio()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar el grado porque tiene planes de estudio asociados'
                ], 409);
            }

            $grado->delete();
            return response()->json(['message' => 'Grado eliminado exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: grado no encontrado',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar el grado',
            ], 500);
        }
    }

    /**
     * Catálogo oficial de grados con su orden e indicación de si ya está registrado.
     */
    public function catalogo()
    {
        $registrados = Grado::pluck('id', 'nombre');

        return response()->json([
            'catalogo' => collect(CatalogoGrados::GRADOS)
                ->map(fn ($orden, $nombre) => ['nombre' => $nombre, 'orden' => $orden, 'grado_id' => $registrados[$nombre] ?? null])
                ->values(),
        ], 200);
    }

    // Acepta el nombre sin importar mayúsculas, tildes o espacios y guarda el oficial
    private function nombreOficial(Request $request): void
    {
        if (is_string($request->nombre) && ($oficial = CatalogoGrados::oficial($request->nombre))) {
            $request->merge(['nombre' => $oficial]);
        }
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre del grado es obligatorio.',
            'nombre.string' => 'El nombre del grado debe ser una cadena de texto.',
            'nombre.in' => 'El grado debe ser uno del catálogo oficial: '.implode(', ', array_keys(CatalogoGrados::GRADOS)).'.',
            'nombre.unique' => 'El grado ya está registrado.',
        ];
    }
}

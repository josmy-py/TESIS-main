<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Encargado;
use App\Rules\Dui;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class EncargadoController extends Controller
{

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nombre' => 'required|string|max:80',
                'parentesco' => 'required|in:Tío(a),Abuelo(a),Hermano(a),Primo(a)',
                'telefono' => 'required|string|max:15|regex:/^[267][0-9]{3}-?[0-9]{4}$/',
                'dui' => ['required', 'string', 'max:10', new Dui(), 'unique:encargados,dui'],
                'direccion' => 'required|string|max:100',
                'user_id' => 'nullable|exists:users,id|unique:encargados,user_id',
            ], [
                'nombre.required' => 'El nombre es obligatorio.',
                'parentesco.required' => 'El parentesco es obligatorio.',
                'telefono.required' => 'El teléfono es obligatorio.',
                'dui.required' => 'El DUI es obligatorio.',
                'dui.unique' => 'El DUI ya está registrado.',
                'direccion.required' => 'La dirección es obligatoria.',
                'user_id.exists' => 'El usuario seleccionado no existe.',
                'user_id.unique' => 'El usuario ya está asociado a un encargado.',
            ]);

            $yaTieneEncargado = Encargado::where('estudiante_id', $validated['estudiante_id'])->exists();

            if ($yaTieneEncargado) {
                return response()->json([
                    'message' => 'El estudiante ya tiene un encargado registrado.'
                ], 409);
            }

            $encargado = Encargado::create([
                'nombre' => $validated['nombre'],
                'parentesco' => $validated['parentesco'],
                'telefono' => $validated['telefono'],
                'dui' => $validated['dui'],
                'direccion' => $validated['direccion'],
                'user_id' => $validated['user_id'] ?? null,
            ]);

            return response()->json([
                'message' => 'Encargado creado exitosamente',
                'encargado' => $encargado
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al registrar el encargado',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $encargado = Encargado::with('matriculas.estudiante')->findOrFail($id);

            return response()->json($encargado);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: encargado no encontrado',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $encargado = Encargado::findOrFail($id);

            $validated = $request->validate([
                'nombre' => 'required|string|max:80',
                'parentesco' => 'required|in:Tío(a),Abuelo(a),Hermano(a),Primo(a)',
                'telefono' => 'required|string|max:15|regex:/^[267][0-9]{3}-?[0-9]{4}$/',
                'dui' => [
                    'required',
                    'string',
                    'max:10',
                    new Dui(),
                    Rule::unique('encargados', 'dui')->ignore($id)
                ],
                'direccion' => 'required|string|max:100',
                'estudiante_id' => [
                    'required',
                    'exists:estudiantes,id',
                    Rule::unique('encargados', 'estudiante_id')->ignore($id)
                ],
                'user_id' => [
                    'nullable',
                    'exists:users,id',
                    Rule::unique('encargados', 'user_id')->ignore($id)
                ],
            ], [
                'nombre.required' => 'El nombre es obligatorio.',
                'parentesco.required' => 'El parentesco es obligatorio.',
                'telefono.required' => 'El teléfono es obligatorio.',
                'dui.required' => 'El DUI es obligatorio.',
                'dui.unique' => 'El DUI ya está registrado.',
                'direccion.required' => 'La dirección es obligatoria.',
                'user_id.exists' => 'El usuario seleccionado no existe.',
                'user_id.unique' => 'El usuario ya está asociado a otro encargado.',
            ]);

            $encargado->update([
                'nombre' => $validated['nombre'],
                'parentesco' => $validated['parentesco'],
                'telefono' => $validated['telefono'],
                'dui' => $validated['dui'],
                'direccion' => $validated['direccion'],
                'user_id' => $validated['user_id'] ?? null,
            ]);

            return response()->json([
                'message' => 'Encargado actualizado exitosamente',
                'encargado' => $encargado
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: encargado no encontrado',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar el encargado',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $encargado = Encargado::findOrFail($id);
            $encargado->delete();

            return response()->json([
                'message' => 'Encargado eliminado exitosamente'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: encargado no encontrado'
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar el encargado',
            ], 500);
        }
    }
}

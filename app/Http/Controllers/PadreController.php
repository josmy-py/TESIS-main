<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\Dui;
use App\Services\PadreService;
use App\Services\UsuarioService;
use App\Support\DuiFormato;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PadreController extends Controller
{
    protected PadreService $padreService;

    protected UsuarioService $usuarioService;

    public function __construct(PadreService $padreService, UsuarioService $usuarioService)
    {
        $this->padreService = $padreService;
        $this->usuarioService = $usuarioService;
    }
    
    public function buscarPorDui(Request $request)
    {
        $request->validate([
            'dui' => 'required|string'
        ]);

        try {
            $padre = $this->padreService->buscarPorDui($request->dui, $this->propietarioId());

            if ($padre) {
                return response()->json([
                    'encontrado' => true,
                    'padre' => $padre
                ], 200);
            }

            return response()->json([
                'encontrado' => false,
                'message' => 'No se encontró ningún padre con este DUI'
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al buscar el padre',
            ], 500);
        }
    }    
   public function store(Request $request)
    {
        try {
            $request->merge(['dui' => DuiFormato::normalizar($request->dui)]);

            $validated = $request->validate([
                'nombre' => 'required|string|max:80',
                'dui' => ['required', new Dui, 'unique:padres,dui'],
                'telefono' => 'nullable|string|max:15',
                'email' => 'nullable|email|max:100',
                'es_encargado' => 'required|boolean',
            ]);

            // Si el padre es la persona de la cuenta, debe ser una cuenta ENCARGADO con el mismo DUI
            $validated['user_id'] = null;
            if ($validated['es_encargado']) {
                $user = $request->user();
                $this->usuarioService->verificarCorrespondencia($user, $validated['dui'], User::ROL_ENCARGADO);
                $validated['user_id'] = $user->id;
            }

            // Cuenta que registra al padre: única que podrá vincularlo mientras no tenga estudiantes
            $validated['registrado_por'] = $request->user()->id;

            $validated['telefono'] = $validated['telefono'] ?? null;
            $validated['email'] = $validated['email'] ?? null;

            $padre = $this->padreService->crear($validated);

            return response()->json([
                'message' => 'Padre registrado exitosamente',
                'padre' => $padre,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\DomainException $e) {
            // Regla de negocio del servicio (usuario ya registrado como padre)
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al registrar el padre',
            ], 500);
        }
    }
}
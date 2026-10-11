<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AsignacionController;
use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\GradoController;
use App\Http\Controllers\SeccionController;
use App\Http\Controllers\DiscapacidadController;
use App\Http\Controllers\EspecialidadController;
use App\Http\Controllers\EnfermedadController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ProfesorController;
use App\Http\Controllers\MatriculaController;
use App\Http\Controllers\PadreController;
use App\Http\Controllers\ParienteController;
use App\Http\Controllers\UbicacionController;
use App\Http\Controllers\UsuarioController;

Route::prefix('auth')->group(function(){
    // Públicas, con límite de intentos
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:registro');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('reenviar-activacion', [AuthController::class, 'reenviarActivacion'])->middleware('throttle:correo');
    Route::post('olvide-contrasena', [AuthController::class, 'olvideContrasena'])->middleware('throttle:correo');
    Route::post('restablecer-contrasena', [AuthController::class, 'restablecerContrasena'])->middleware('throttle:restablecer');

    // Enlace de activación firmado y temporal (lo llama el frontend desde /activar)
    Route::get('activar/{id}/{hash}', [AuthController::class, 'activar'])
        ->middleware(['signed', 'throttle:activacion'])
        ->name('auth.activar');

    // La validación del DUI (formato, dígito verificador y unicidad) se hace en register;
    // ya no existe un endpoint validar-dui separado (decisión A2 de M1).

    Route::middleware(['auth:api', 'activo'])->group(function(){
        Route::get('me',[AuthController::class, 'me']);
        Route::post('logout',[AuthController::class, 'logout']);
        Route::post('refresh',[AuthController::class, 'refresh']);
        Route::put('contrasena',[AuthController::class, 'cambiarContrasena']);
    });
});

Route::middleware(['auth:api', 'activo'])->group(function () {
    // Estas rutas de consulta deben estar accesibles para el Cliente (para llenar los selects)
    Route::apiResource('grados', GradoController::class)->only(['index', 'show']);
    Route::apiResource('especialidades', EspecialidadController::class)->only(['index', 'show']);
    Route::apiResource('discapacidades', DiscapacidadController::class)->only(['index', 'show']);
    Route::apiResource('enfermedades', EnfermedadController::class)->only(['index', 'show']);

    // División territorial (M2.1.5): antes era pública; ahora requiere sesión como el resto de catálogos
    Route::get('departamentos', [UbicacionController::class, 'departamentos']);
    Route::get('departamentos/{departamento}/municipios', [UbicacionController::class, 'municipios']);
    Route::get('municipios/{municipio}/distritos', [UbicacionController::class, 'distritosDeMunicipio']);
    Route::get('distritos', [UbicacionController::class, 'distritos']);
    Route::get('distritos/{id}', [UbicacionController::class, 'distrito']);
});

// 🟢 1. Rutas accesibles para Encargados y Administradores
// Los controladores limitan al ENCARGADO a los registros de su propia cuenta.
Route::middleware(['auth:api', 'activo', 'role:ENCARGADO|DIRECTOR'])->group(function () {
    Route::post('padres/buscar-dui', [PadreController::class, 'buscarPorDui']);
    Route::apiResource('estudiantes', EstudianteController::class)->only(['store', 'update']);
    Route::apiResource('padres', PadreController::class)->only(['store']);
    Route::apiResource('parientes', ParienteController::class)->only(['store']);

    // Métodos adicionales de matrículas: se declaran antes del apiResource
    // para que matriculas/{matricula} no capture rutas como estudiantes-inscritos
    Route::post('matriculas/buscar-nie', [MatriculaController::class, 'buscarNie']);
    Route::get('matriculas/estudiantes-inscritos', [MatriculaController::class, 'estudianteInscrito']);
    Route::get('matriculas/estudiantes/{estudianteId}/padres', [MatriculaController::class, 'padresEstudiante']);
    Route::post('matriculas/validar-grado/{estudiante}/{grado}', [MatriculaController::class, 'validarGrado']);
    Route::post('matriculas/disponibilidad-seccion', [MatriculaController::class, 'disponibilidadSeccion']);
    Route::post('matriculas/secciones-disponibles', [MatriculaController::class, 'seccionesDisponibles']);
    Route::post('matriculas/duplicidad', [MatriculaController::class, 'duplicidadMatricula']);
    Route::apiResource('matriculas', MatriculaController::class)->except(['destroy']);
});

// 🔴 2. Rutas Exclusivas para el Administrador (Gestión del Sistema)
Route::middleware(['auth:api', 'activo', 'role:DIRECTOR'])->group(function () {
    // Gestión de usuarios (M1)
    Route::get('usuarios', [UsuarioController::class, 'index']);
    Route::post('usuarios', [UsuarioController::class, 'store']);
    Route::get('usuarios/{id}', [UsuarioController::class, 'show']);
    Route::put('usuarios/{id}', [UsuarioController::class, 'update']);
    Route::patch('usuarios/{id}/estado', [UsuarioController::class, 'cambiarEstado']);
    Route::post('usuarios/{id}/reenviar-enlace', [UsuarioController::class, 'reenviarEnlace']);
    Route::delete('usuarios/{id}', [UsuarioController::class, 'destroy']);

    // Aprobar o rechazar matrículas es una acción del Director
    Route::put('matriculas/{id}/estado', [MatriculaController::class, 'estadoMatricula']);
    Route::apiResource('asignaturas', AsignaturaController::class);
    Route::apiResource('grados', GradoController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('especialidades', EspecialidadController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('discapacidades', DiscapacidadController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('enfermedades', EnfermedadController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('secciones', SeccionController::class);
    Route::apiResource('profesores', ProfesorController::class);
    Route::apiResource('asignaciones', AsignacionController::class)->except(['update']);
});

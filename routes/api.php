<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AnioLectivoController;
use App\Http\Controllers\AsignacionController;
use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\GradoController;
use App\Http\Controllers\SeccionController;
use App\Http\Controllers\DiscapacidadController;
use App\Http\Controllers\EspecialidadController;
use App\Http\Controllers\EncargadoController;
use App\Http\Controllers\EnfermedadController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ProfesorController;
use App\Http\Controllers\MatriculaController;
use App\Http\Controllers\PadreController;
use App\Http\Controllers\PeriodoEscolarController;
use App\Http\Controllers\PlanEstudioController;
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
    Route::get('grados/catalogo', [GradoController::class, 'catalogo']);
    Route::apiResource('grados', GradoController::class)->only(['index', 'show']);
    Route::apiResource('especialidades', EspecialidadController::class)->only(['index', 'show']);
    Route::apiResource('discapacidades', DiscapacidadController::class)->only(['index', 'show']);
    Route::apiResource('enfermedades', EnfermedadController::class)->only(['index', 'show']);
    // M2.3: plan de estudios y asignaturas (pensum por grado)
    Route::apiResource('plan-estudios', PlanEstudioController::class)->only(['index', 'show']);
    Route::apiResource('asignaturas', AsignaturaController::class)->only(['index', 'show']);

    // División territorial (M2.1.5): antes era pública; ahora requiere sesión como el resto de catálogos
    Route::get('departamentos', [UbicacionController::class, 'departamentos']);
    Route::get('departamentos/{departamento}/municipios', [UbicacionController::class, 'municipios']);
    Route::get('municipios/{municipio}/distritos', [UbicacionController::class, 'distritosDeMunicipio']);
    Route::get('distritos', [UbicacionController::class, 'distritos']);
    Route::get('distritos/{id}', [UbicacionController::class, 'distrito']);

    // Calendario escolar (M2.2): consultas para cualquier cuenta con sesión
    Route::get('anios-lectivos/actual', [AnioLectivoController::class, 'actual']);
    Route::get('anios-lectivos', [AnioLectivoController::class, 'index']);
    Route::get('anios-lectivos/{id}', [AnioLectivoController::class, 'show'])->whereNumber('id');
    Route::get('anios-lectivos/{id}/periodos', [AnioLectivoController::class, 'periodos'])->whereNumber('id');
    Route::get('periodos-escolares/{id}', [PeriodoEscolarController::class, 'show'])->whereNumber('id');
});

// 🟢 1. Rutas accesibles para Encargados y Administradores
// Los controladores limitan al ENCARGADO a los registros de su propia cuenta.
Route::middleware(['auth:api', 'activo', 'role:ENCARGADO|DIRECTOR'])->group(function () {
    Route::post('padres/buscar-dui', [PadreController::class, 'buscarPorDui']);
    // M5: estudiantes y familia (D3). El encargado solo ve lo de su cuenta.
    Route::get('familia/perfil', [EncargadoController::class, 'perfil']);
    Route::apiResource('estudiantes', EstudianteController::class);
    Route::post('estudiantes/{id}/padres', [ParienteController::class, 'vincular'])->whereNumber('id');
    Route::delete('estudiantes/{id}/padres/{padre}', [ParienteController::class, 'desvincular'])->whereNumber(['id', 'padre']);
    Route::apiResource('padres', PadreController::class)->only(['index', 'show', 'store', 'update']);
    Route::apiResource('parientes', ParienteController::class)->only(['store']);
    Route::apiResource('encargados', EncargadoController::class)->only(['index', 'show', 'store', 'update']);

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

    // M5: D-E3 cambio de cuenta del estudiante; eliminar encargados sin matrículas
    Route::put('estudiantes/{id}/cuenta', [EstudianteController::class, 'cambiarCuenta'])->whereNumber('id');
    Route::delete('encargados/{encargado}', [EncargadoController::class, 'destroy']);

    // Aprobar o rechazar matrículas es una acción del Director
    Route::put('matriculas/{id}/estado', [MatriculaController::class, 'estadoMatricula']);
    Route::apiResource('plan-estudios', PlanEstudioController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('asignaturas', AsignaturaController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('grados', GradoController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('especialidades', EspecialidadController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('discapacidades', DiscapacidadController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('enfermedades', EnfermedadController::class)->only(['store', 'update', 'destroy']);

    // Calendario escolar (M2.2): administración y transiciones de estado
    Route::get('anios-lectivos/diagnostico', [AnioLectivoController::class, 'diagnostico']);
    Route::post('anios-lectivos', [AnioLectivoController::class, 'store']);
    Route::put('anios-lectivos/{id}', [AnioLectivoController::class, 'update'])->whereNumber('id');
    Route::delete('anios-lectivos/{id}', [AnioLectivoController::class, 'destroy'])->whereNumber('id');
    Route::post('anios-lectivos/{id}/activar', [AnioLectivoController::class, 'activar'])->whereNumber('id');
    Route::post('anios-lectivos/{id}/cerrar', [AnioLectivoController::class, 'cerrar'])->whereNumber('id');
    Route::post('anios-lectivos/{id}/periodos', [AnioLectivoController::class, 'agregarPeriodo'])->whereNumber('id');
    Route::put('periodos-escolares/{id}', [PeriodoEscolarController::class, 'update'])->whereNumber('id');
    Route::delete('periodos-escolares/{id}', [PeriodoEscolarController::class, 'destroy'])->whereNumber('id');
    Route::post('periodos-escolares/{id}/abrir', [PeriodoEscolarController::class, 'abrir'])->whereNumber('id');
    Route::post('periodos-escolares/{id}/cerrar', [PeriodoEscolarController::class, 'cerrar'])->whereNumber('id');
    Route::post('periodos-escolares/{id}/reabrir', [PeriodoEscolarController::class, 'reabrir'])->whereNumber('id');
    Route::apiResource('secciones', SeccionController::class);
    Route::apiResource('profesores', ProfesorController::class);
    // M4: asignación académica (PUT solo cambia el docente; la carga de un docente se guarda de una vez)
    Route::apiResource('asignaciones', AsignacionController::class);
    Route::put('profesores/{id}/carga', [AsignacionController::class, 'guardarCarga'])->whereNumber('id');
});

// M4: cada docente consulta su propia carga académica (tesis, págs. 58 y 68)
Route::middleware(['auth:api', 'activo', 'role:DOCENTE'])->group(function () {
    Route::get('docente/carga', [AsignacionController::class, 'miCarga']);
});

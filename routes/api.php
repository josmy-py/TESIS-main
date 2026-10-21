<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AnioLectivoController;
use App\Http\Controllers\AsignacionController;
use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\PlanAsignaturaController;
use App\Http\Controllers\GradoController;
use App\Http\Controllers\SeccionController;
use App\Http\Controllers\DiscapacidadController;
use App\Http\Controllers\EspecialidadController;
use App\Http\Controllers\EncargadoController;
use App\Http\Controllers\EnfermedadController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ProfesorController;
use App\Http\Controllers\MatriculaController;
use App\Http\Controllers\NotasController;
use App\Http\Controllers\ConductaController;
use App\Http\Controllers\ConstanciaController;
use App\Http\Controllers\ConsultaAcademicaController;
use App\Http\Controllers\LibretaController;
use App\Http\Controllers\ReporteController;
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

    Route::middleware(['auth:api', 'sesion'])->group(function(){
        Route::get('me',[AuthController::class, 'me']);
        Route::post('logout',[AuthController::class, 'logout']);
        Route::post('refresh',[AuthController::class, 'refresh']);
        Route::put('contrasena',[AuthController::class, 'cambiarContrasena']);
    });
});

Route::middleware(['auth:api', 'sesion'])->group(function () {

    // Estas rutas de consulta deben estar accesibles para el Cliente (para llenar los selects)
    Route::get('grados/catalogo', [GradoController::class, 'catalogo']);
    Route::apiResource('grados', GradoController::class)->only(['index', 'show']);
    Route::apiResource('especialidades', EspecialidadController::class)->only(['index', 'show']);
    Route::apiResource('discapacidades', DiscapacidadController::class)->only(['index', 'show']);
    Route::apiResource('enfermedades', EnfermedadController::class)->only(['index', 'show']);
    // M2.3: plan de estudios y asignaturas (pensum por grado)
    Route::apiResource('plan-estudios', PlanEstudioController::class)->only(['index', 'show']);
    Route::apiResource('asignaturas', AsignaturaController::class)->only(['index', 'show']);
    // Diagrama ER (21/10/2026): materias de cada plan (plan_asignaturas)
    Route::apiResource('plan-asignaturas', PlanAsignaturaController::class)->only(['index', 'show']);

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
Route::middleware(['auth:api', 'sesion', 'role:ENCARGADO|DIRECTOR'])->group(function () {
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
    // M6.1: opciones de promoción, archivos privados (D-M9) y cancelar pendientes (D-M7)
    Route::get('matriculas/estudiantes/{estudianteId}/opciones', [MatriculaController::class, 'opciones'])->whereNumber('estudianteId');
    Route::get('matriculas/{id}/archivos/{tipo}', [MatriculaController::class, 'archivo'])->whereNumber('id')->whereIn('tipo', ['foto', 'documento']);
    // M11: Matricula.generarComprobante()
    Route::get('matriculas/{id}/comprobante', [ConsultaAcademicaController::class, 'comprobante'])->whereNumber('id');
    Route::apiResource('matriculas', MatriculaController::class);
    // M10: Consultar estudiante > Consultar notas / Consultar conducta
    Route::get('estudiantes/{id}/notas', [ConsultaAcademicaController::class, 'notas'])->whereNumber('id');
    Route::get('estudiantes/{id}/asignaturas', [ConsultaAcademicaController::class, 'asignaturas'])->whereNumber('id');
    Route::get('estudiantes/{id}/conducta', [ConsultaAcademicaController::class, 'conducta'])->whereNumber('id');
});

// 🔴 2. Rutas Exclusivas para el Administrador (Gestión del Sistema)
Route::middleware(['auth:api', 'sesion', 'role:DIRECTOR'])->group(function () {
    // Gestión de usuarios (M1)
    Route::get('usuarios', [UsuarioController::class, 'index']);
    Route::post('usuarios', [UsuarioController::class, 'store']);
    Route::get('usuarios/{id}', [UsuarioController::class, 'show']);
    Route::put('usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('usuarios/{id}', [UsuarioController::class, 'destroy']);

    // M5: eliminar encargados sin matrículas
    Route::delete('encargados/{encargado}', [EncargadoController::class, 'destroy']);

    // Aprobar o rechazar matrículas es una acción del Director
    Route::put('matriculas/{id}/estado', [MatriculaController::class, 'estadoMatricula'])->whereNumber('id');
    Route::get('matriculas/{id}/secciones', [MatriculaController::class, 'seccionesParaAsignar'])->whereNumber('id');
    Route::apiResource('plan-estudios', PlanEstudioController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('asignaturas', AsignaturaController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('plan-asignaturas', PlanAsignaturaController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('grados', GradoController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('especialidades', EspecialidadController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('discapacidades', DiscapacidadController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('enfermedades', EnfermedadController::class)->only(['store', 'update', 'destroy']);

    // Calendario escolar (M2.2): administración
    Route::post('anios-lectivos', [AnioLectivoController::class, 'store']);
    Route::put('anios-lectivos/{id}', [AnioLectivoController::class, 'update'])->whereNumber('id');
    Route::delete('anios-lectivos/{id}', [AnioLectivoController::class, 'destroy'])->whereNumber('id');
    Route::post('anios-lectivos/{id}/activar', [AnioLectivoController::class, 'activar'])->whereNumber('id');
    Route::post('anios-lectivos/{id}/cerrar', [AnioLectivoController::class, 'cerrar'])->whereNumber('id');
    Route::post('anios-lectivos/{id}/periodos', [AnioLectivoController::class, 'agregarPeriodo'])->whereNumber('id');
    Route::put('periodos-escolares/{id}', [PeriodoEscolarController::class, 'update'])->whereNumber('id');
    Route::delete('periodos-escolares/{id}', [PeriodoEscolarController::class, 'destroy'])->whereNumber('id');
    Route::put('periodos-escolares/{id}/estado', [PeriodoEscolarController::class, 'estado'])->whereNumber('id');
    Route::apiResource('secciones', SeccionController::class);
    Route::get('profesores/cuentas-disponibles', [ProfesorController::class, 'cuentasDisponibles']);
    Route::apiResource('profesores', ProfesorController::class);
    // M4: asignación académica (PUT solo cambia el docente; la carga de un docente se guarda de una vez)
    Route::apiResource('asignaciones', AsignacionController::class);
    Route::put('profesores/{id}/carga', [AsignacionController::class, 'guardarCarga'])->whereNumber('id');
    // M11: Registrar estudiante > Consultar expediente
    Route::get('estudiantes/{id}/expediente', [ConsultaAcademicaController::class, 'expediente'])->whereNumber('id');
    // M12: Generar reportes académicos
    Route::get('reportes/notas', [ReporteController::class, 'notas']);
    Route::get('reportes/conducta', [ReporteController::class, 'conducta']);
    Route::get('reportes/academico/indicadores', [ReporteController::class, 'academicoIndicadores']);
    Route::post('reportes/academico/pdf', [ReporteController::class, 'academicoPdf']);
    Route::get('reportes/docente/indicadores', [ReporteController::class, 'docenteIndicadores']);
    Route::get('reportes/conducta/indicadores', [ReporteController::class, 'conductaIndicadores']);
    Route::post('reportes/conducta/pdf', [ReporteController::class, 'conductaDirectorPdf']);
    Route::get('reportes/institucional/indicadores', [ReporteController::class, 'institucionalIndicadores']);
    Route::post('reportes/institucional/pdf', [ReporteController::class, 'institucionalPdf']);
    Route::post('reportes/docente/pdf', [ReporteController::class, 'docentePdf']);
    // Constancias (por ahora la de egresado)
    Route::get('constancias/egresado', [ConstanciaController::class, 'egresado']);
    Route::post('constancias/egresado/pdf', [ConstanciaController::class, 'egresadoPdf']);
});

// M4: cada docente consulta su propia carga académica (tesis, págs. 58 y 68)
Route::middleware(['auth:api', 'sesion', 'role:DOCENTE'])->group(function () {
    Route::get('docente/carga', [AsignacionController::class, 'miCarga']);
    // M7 Notas: estudiantes, evaluaciones y calificaciones de sus asignaciones
    Route::get('docente/asignaciones/{id}/estudiantes', [NotasController::class, 'estudiantes'])->whereNumber('id');
    Route::get('docente/asignaciones/{id}/evaluaciones', [NotasController::class, 'evaluaciones'])->whereNumber('id');
    Route::post('docente/asignaciones/{id}/evaluaciones', [NotasController::class, 'crearEvaluacion'])->whereNumber('id');
    Route::delete('docente/evaluaciones/{id}', [NotasController::class, 'eliminarEvaluacion'])->whereNumber('id');
    Route::get('docente/evaluaciones/{id}/calificaciones', [NotasController::class, 'calificaciones'])->whereNumber('id');
    Route::put('docente/evaluaciones/{id}/calificaciones', [NotasController::class, 'guardarCalificaciones'])->whereNumber('id');
    // M8 Conducta: registrar observaciones y seguimiento conductual
    Route::get('docente/secciones/{id}/conducta', [ConductaController::class, 'index'])->whereNumber('id');
    Route::put('docente/secciones/{id}/conducta', [ConductaController::class, 'guardar'])->whereNumber('id');
    // M9 Libreta: boletas de una materia por periodo (la nota final se calcula sola)
    Route::get('docente/asignaciones/{id}/libreta', [LibretaController::class, 'show'])->whereNumber('id');
    Route::post('docente/asignaciones/{id}/libreta', [LibretaController::class, 'generar'])->whereNumber('id');
    // M12 Libreta > Reporte de notas y Reporte de conducta
    Route::get('docente/reportes/notas', [ReporteController::class, 'notas']);
    Route::get('docente/reportes/conducta', [ReporteController::class, 'conducta']);
    Route::post('docente/reportes/informe-profesor/pdf', [ReporteController::class, 'informeProfesorPdf']);
    Route::post('docente/reportes/libreta-notas/pdf', [ReporteController::class, 'libretaNotasPdf']);
    Route::post('docente/reportes/rendimiento/pdf', [ReporteController::class, 'rendimientoPdf']);
    Route::post('docente/reportes/conducta/pdf', [ReporteController::class, 'conductaPdf']);
});

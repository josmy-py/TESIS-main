<?php

namespace Database\Seeders;

use App\Models\AnioLectivo;
use App\Models\Asignacion;
use App\Models\Boleta;
use App\Models\Calificacion;
use App\Models\Conducta;
use App\Models\Discapacidad;
use App\Models\Distrito;
use App\Models\Especialidad;
use App\Models\Estudiante;
use App\Models\Evaluacion;
use App\Models\Grado;
use App\Models\Matricula;
use App\Models\Padre;
use App\Models\Pariente;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use App\Models\User;
use App\Services\AsignacionService;
use App\Services\CalendarioService;
use App\Services\LibretaService;
use App\Support\CatalogoGrados;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Escenarios reproducibles para probar M5-M10 sin alterar funcionalidad ni datos previos.
 * Solo desarrollo/pruebas. Se puede ejecutar varias veces: no pisa notas ni
 * registros editados por compañeros. Requiere los seeders previos de DatabaseSeeder.
 */
class DatosAcademicosPruebaSeeder extends Seeder
{
    /** NIE exclusivos de las muestras; las aprobadas usan una cuenta separada para probar el menú académico. */
    private const ALUMNOS = [
        ['nie' => '9101001', 'nombre' => 'Sofía Ramírez López',   'fecha' => '2020-02-14', 'genero' => 'Femenino',  'grado' => 'Primer grado',  'estado' => 'aprobado'],
        ['nie' => '9101002', 'nombre' => 'Diego Martínez Rivera', 'fecha' => '2019-06-20', 'genero' => 'Masculino', 'grado' => 'Primer grado',  'estado' => 'aprobado'],
        ['nie' => '9101003', 'nombre' => 'Valeria Castro Flores', 'fecha' => '2020-09-03', 'genero' => 'Femenino',  'grado' => 'Primer grado',  'estado' => 'aprobado'],
        ['nie' => '9101004', 'nombre' => 'Daniel Hernández Ruiz', 'fecha' => '2019-11-18', 'genero' => 'Masculino', 'grado' => 'Primer grado',  'estado' => 'aprobado'],
        ['nie' => '9102001', 'nombre' => 'Andrea López Mendoza',  'fecha' => '2018-03-06', 'genero' => 'Femenino',  'grado' => 'Segundo grado', 'estado' => 'aprobado'],
        ['nie' => '9102002', 'nombre' => 'Kevin Morales Díaz',    'fecha' => '2019-01-22', 'genero' => 'Masculino', 'grado' => 'Segundo grado', 'estado' => 'aprobado'],
        ['nie' => '9102003', 'nombre' => 'Camila Torres Pérez',    'fecha' => '2018-08-09', 'genero' => 'Femenino',  'grado' => 'Segundo grado', 'estado' => 'aprobado'],
        ['nie' => '9102004', 'nombre' => 'Samuel García Vásquez', 'fecha' => '2018-12-12', 'genero' => 'Masculino', 'grado' => 'Segundo grado', 'estado' => 'aprobado'],
        ['nie' => '9101005', 'nombre' => 'Mariana Rivas Soto',    'fecha' => '2020-04-05', 'genero' => 'Femenino',  'grado' => 'Primer grado',  'estado' => 'pendiente'],
        ['nie' => '9102005', 'nombre' => 'José Aguilar Romero',   'fecha' => '2018-07-17', 'genero' => 'Masculino', 'grado' => 'Segundo grado', 'estado' => 'rechazado'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DatosAcademicosPruebaSeeder no se ejecuta en producción.');
            return;
        }

        // Catálogos generales: no se borran, renombran ni modifican datos existentes.
        foreach (array_keys(CatalogoGrados::GRADOS) as $nombre) {
            Grado::firstOrCreate(['nombre' => $nombre]);
        }
        Especialidad::firstOrCreate(['nombre' => 'Bachillerato General']);

        $cuenta = User::where('email', 'encargado@siga.test')->first();
        $docente = User::where('email', 'docente@siga.test')->first()?->profesor;
        $distrito = Distrito::orderBy('id')->first();
        $padre = $cuenta ? Padre::where('user_id', $cuenta->id)->first() : null;
        $madre = Padre::where('dui', '00000020-4')->first();

        if (! $cuenta || ! $docente || ! $distrito || ! $padre || ! $madre) {
            $this->command?->warn('DatosAcademicosPruebaSeeder: faltan los usuarios, familia, docente o geografía de prueba.');
            return;
        }

        // Una segunda cuenta con matrículas aprobadas y sin solicitudes pendientes:
        // la cuenta original tiene pendientes y mostraría solo ese flujo en el menú.
        $cuentaAprobada = User::where('email', 'encargado.aprobado@siga.test')->first();
        if (! $cuentaAprobada) {
            $password = config('app.seed_password') ?: Str::password(16, symbols: false);
            $cuentaAprobada = new User([
                'email' => 'encargado.aprobado@siga.test',
                'password' => Hash::make($password),
            ]);
            $cuentaAprobada->asignarRol(User::ROL_ENCARGADO);
            $cuentaAprobada->save();
            if (! config('app.seed_password')) {
                $this->command?->info("Cuenta {$cuentaAprobada->email} creada con contraseña: {$password}");
            }
        }
        if (! $cuentaAprobada->hasRole(User::ROL_ENCARGADO)) {
            $this->command?->warn('La cuenta encargado.aprobado@siga.test ya existe con un rol diferente.');
            return;
        }
        $padreAprobadas = Padre::firstOrCreate(['dui' => '00000003-4'], [
            'nombre' => 'Carlos Ramírez de Prueba', 'telefono' => '7000-0003',
            'email' => $cuentaAprobada->email, 'user_id' => $cuentaAprobada->id,
        ]);
        if ($padreAprobadas->user_id === null
            && ! Padre::where('user_id', $cuentaAprobada->id)->exists()) {
            $padreAprobadas->update(['user_id' => $cuentaAprobada->id]);
        }
        if ((int) $padreAprobadas->user_id !== (int) $cuentaAprobada->id) {
            $this->command?->warn('El padre de la familia aprobada pertenece a otra cuenta.');
            return;
        }
        $madreAprobadas = Padre::firstOrCreate(['dui' => '00000021-2'], [
            'nombre' => 'Elena López de Prueba', 'telefono' => '7000-0021', 'email' => null,
        ]);

        // Se usan únicamente las secciones creadas por SeccionPruebaSeeder para el
        // docente de prueba; no se intervienen secciones de terceros.
        $secciones = [];
        foreach (['Primer grado', 'Segundo grado'] as $nombreGrado) {
            $gradoId = Grado::where('nombre', $nombreGrado)->value('id');
            $secciones[$nombreGrado] = Seccion::with('anioLectivo')
                ->where('grado_id', $gradoId)->where('profesor_id', $docente->id)
                ->where('nombre', 'A')
                ->whereHas('anioLectivo', fn ($q) => $q->where('estado', '<>', AnioLectivo::CIERRE))
                ->orderByDesc('anio_lectivo_id')->first();
        }
        if (! $secciones['Primer grado'] || ! $secciones['Segundo grado']) {
            $this->command?->warn('DatosAcademicosPruebaSeeder: faltan las secciones A de prueba, no se crearon matrículas.');
            return;
        }

        // Si la base acaba de crearse y solo existe 2027, dejarlo activo para
        // permitir editar notas y conducta desde la interfaz. No activar ni
        // desactivar años existentes cuando otro ya está activo.
        $anios = collect($secciones)->pluck('anioLectivo')->unique('id');
        foreach ($anios as $anio) {
            // La columna del diagrama es "anio" (migración 000016); "nombre" ya no existe
            if ((int) $anio->anio === SeccionPruebaSeeder::ANIO_PRUEBA
                && $anio->estado === AnioLectivo::INACTIVO
                && AnioLectivo::activo() === null && $anio->fechasValidas()) {
                try {
                    app(CalendarioService::class)->activar($anio->id);
                } catch (\DomainException $e) {
                    $this->command?->warn("No se activó el año {$anio->anio}: {$e->getMessage()}");
                }
            }
        }

        // Completa las materias previstas por AsignacionPruebaSeeder sin duplicar
        // asignaciones ni cambiar docentes o planes académicos existentes. Pasa
        // por AsignacionService para respetar las reglas de M4.
        $servicioAsignaciones = app(AsignacionService::class);
        foreach ($secciones as $nombreGrado => $seccion) {
            $plan = $servicioAsignaciones->planDeSeccion($seccion->id);
            if (! $plan || ! $plan->estado) {
                continue;
            }
            foreach ($plan->asignaturas()->whereIn('nombre', AsignacionPruebaSeeder::PENSUM[$nombreGrado])->get() as $asignatura) {
                if (Asignacion::where('seccion_id', $seccion->id)->where('asignatura_id', $asignatura->id)->exists()) {
                    continue;
                }
                try {
                    $servicioAsignaciones->crear([
                        'profesor_id' => $docente->id,
                        'seccion_id' => $seccion->id,
                        'asignatura_id' => $asignatura->id,
                    ]);
                } catch (ValidationException|\DomainException $e) {
                    $this->command?->warn("No se asignó {$asignatura->nombre} en {$nombreGrado} A: ".($e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage()));
                }
            }
        }

        $creadas = 0;
        $matriculas = [];
        foreach (self::ALUMNOS as $indice => $datos) {
            $seccion = $secciones[$datos['grado']];
            $cuentaEstudiante = $datos['estado'] === 'aprobado' ? $cuentaAprobada : $cuenta;
            $padresEstudiante = $datos['estado'] === 'aprobado'
                ? [[$padreAprobadas, 'Padre'], [$madreAprobadas, 'Madre']]
                : [[$padre, 'Padre'], [$madre, 'Madre']];
            $estudiante = Estudiante::firstOrCreate(['nie' => $datos['nie']], [
                'nombre' => $datos['nombre'],
                'fecha_nacimiento' => $datos['fecha'],
                'genero' => $datos['genero'],
                'distrito_id' => $distrito->id,
                'direccion' => 'Colonia Escuela, casa '.($indice + 1),
                'canton' => 'Centro',
                'estado' => $datos['estado'] === 'aprobado' ? 'activo' : 'inscrito',
                'user_id' => $cuentaEstudiante->id,
            ]);
            // En instalaciones con datos previos, no vincular ni matricular
            // estudiantes que pertenecen a otra familia aunque coincida el NIE.
            if ((int) $estudiante->user_id !== (int) $cuentaEstudiante->id) {
                $this->command?->warn("Se omite NIE {$datos['nie']}: pertenece a otra cuenta.");
                continue;
            }

            foreach ($padresEstudiante as [$persona, $parentesco]) {
                if (! Pariente::where('estudiante_id', $estudiante->id)
                    ->where('parentesco', $parentesco)->exists()
                    && ! Pariente::where('estudiante_id', $estudiante->id)
                        ->where('padre_id', $persona->id)->exists()) {
                    Pariente::create([
                        'estudiante_id' => $estudiante->id,
                        'padre_id' => $persona->id,
                        'parentesco' => $parentesco,
                    ]);
                }
            }

            $matricula = Matricula::where('estudiante_id', $estudiante->id)
                ->where('anio_lectivo_id', $seccion->anio_lectivo_id)->first();
            if (! $matricula) {
                // Evita exceder la capacidad si ya se probaron altas manuales.
                $cupos = Matricula::where('seccion_id', $seccion->id)
                    ->whereIn('estado', Seccion::ESTADOS_OCUPAN_CUPO)->count();
                if ($datos['estado'] !== 'rechazado' && $cupos >= $seccion->capacidad) {
                    $this->command?->warn("Sin cupo para NIE {$datos['nie']} en {$datos['grado']} A.");
                    continue;
                }

                [$foto, $documento] = $this->archivos($datos['nie'], $datos['nombre']);
                $matricula = Matricula::create([
                    'ingreso' => 'Nuevo ingreso',
                    'anio_lectivo_id' => $seccion->anio_lectivo_id,
                    'estado' => $datos['estado'],
                    'foto' => $foto,
                    'documento' => $documento,
                    'estudiante_id' => $estudiante->id,
                    'seccion_id' => $datos['estado'] === 'aprobado' ? $seccion->id : null,
                    'es_trasladado' => false,
                    'especialidad_id' => null,
                    'enfermedad_id' => null,
                    'encargado_id' => null,
                ]);
                $creadas++;
            }
            // Solo se generan notas a las matrículas realmente aprobadas y
            // ubicadas en la sección del conjunto de prueba.
            if ($matricula->estado === 'aprobado'
                && (int) $matricula->seccion_id === (int) $seccion->id) {
                $matriculas[$seccion->id][$matricula->id] = [$matricula, $indice];
            }
        }

        $discapacidad = Discapacidad::where('nombre', 'Visual')->first();
        if ($discapacidad && $creadas > 0) {
            $uno = Matricula::whereHas('estudiante', fn ($q) => $q->where('nie', '9101001'))->first();
            if ($uno && ! $uno->discapacidades()->exists()) {
                $uno->discapacidades()->syncWithoutDetaching([$discapacidad->id]);
            }
        }

        $libreta = app(LibretaService::class);
        foreach ($secciones as $seccion) {
            $periodos = PeriodoEscolar::deLaSeccion($seccion)->with('anioLectivo')->get();
            $asignaciones = Asignacion::where('seccion_id', $seccion->id)->get();
            $estudiantesDeSeccion = $matriculas[$seccion->id] ?? [];

            foreach ($periodos->values() as $numeroPeriodo => $periodo) {
                // Regla de M7/M8: no se registran notas ni conducta en un periodo
                // cerrado ni en un año lectivo que no está activo
                if (! $periodo->permiteRegistros()) {
                    continue;
                }
                foreach ($estudiantesDeSeccion as [$matricula, $indice]) {
                    Conducta::firstOrCreate([
                        'matricula_id' => $matricula->id,
                        'periodo_escolar_id' => $periodo->id,
                    ], [
                        'valoracion' => $indice % 5 === 0 ? 'Bueno' : 'Excelente',
                        'descripcion' => 'Participa en clase y respeta las normas del centro educativo.',
                    ]);
                }

                foreach ($asignaciones as $asignacion) {
                    $evaluaciones = Evaluacion::where('asignacion_id', $asignacion->id)
                        ->where('periodo_escolar_id', $periodo->id)->get();
                    if ($evaluaciones->isEmpty()) {
                        // 40 + 60 = 100; no añadir evaluaciones a periodos ya usados
                        // por compañeros, pues se excedería el porcentaje permitido.
                        foreach (['Actividad práctica' => 40, 'Evaluación trimestral' => 60] as $nombre => $porcentaje) {
                            Evaluacion::firstOrCreate([
                                'asignacion_id' => $asignacion->id,
                                'periodo_escolar_id' => $periodo->id,
                                'nombre' => $nombre,
                            ], ['porcentaje' => $porcentaje]);
                        }
                        $evaluaciones = Evaluacion::where('asignacion_id', $asignacion->id)
                            ->where('periodo_escolar_id', $periodo->id)->get();
                    }
                    if (abs((float) $evaluaciones->sum('porcentaje') - 100.0) > 0.001) {
                        continue;
                    }
                    foreach ($estudiantesDeSeccion as [$matricula, $indice]) {
                        $promedio = 0.0;
                        foreach ($evaluaciones as $evalIndice => $evaluacion) {
                            $nota = $indice % 4 === 0 ? 5.5 + (($numeroPeriodo + 1 + $evalIndice) % 3) / 10
                                : 7.5 + (($indice + $numeroPeriodo + 1 + $evalIndice) % 18) / 10;
                            $calificacion = Calificacion::firstOrCreate([
                                'matricula_id' => $matricula->id,
                                'evaluacion_id' => $evaluacion->id,
                            ], ['nota_obtenida' => round(min($nota, 10), 2)]);
                            $promedio += (float) $calificacion->nota_obtenida * (float) $evaluacion->porcentaje / 100;
                        }
                        Boleta::firstOrCreate([
                            'matricula_id' => $matricula->id,
                            'asignacion_id' => $asignacion->id,
                            'periodo_escolar_id' => $periodo->id,
                        ], ['promedio' => round($promedio, 2)]);
                    }
                }
            }

            // Nota final con la misma regla de la libreta (M9): solo cuando hay
            // boletas de TODAS las materias y periodos de la sección.
            foreach ($estudiantesDeSeccion as [$matricula]) {
                $libreta->calcularNotaFinal($matricula);
            }
        }

        $this->command?->info("Datos académicos de prueba: {$creadas} matrícula(s) nueva(s); catálogos, notas, conducta y libretas disponibles.");
    }

    /** Archivos de muestra privados, uno por matrícula para que su borrado sea independiente. */
    private function archivos(string $nie, string $nombre): array
    {
        $disco = Storage::disk('matriculas');
        $foto = "fotos/siga-demo-{$nie}.png";
        $documento = "documentos/siga-demo-{$nie}.pdf";
        if (! $disco->exists($foto)) {
            $disco->put($foto, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNoAAAAggCB3UNq9AAAAABJRU5ErkJggg=='));
        }
        if (! $disco->exists($documento)) {
            $texto = "Partida de nacimiento de prueba: {$nombre}";
            // PDF pequeño, legible por la ruta privada de matrículas.
            $textoPdf = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
            $contenido = "BT /F1 12 Tf 50 750 Td ({$textoPdf}) Tj ET";
            $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
                ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
                ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
                .'4 0 obj<</Length '.strlen($contenido).">>stream\n{$contenido}\nendstream endobj\n"
                ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
            $disco->put($documento, $pdf);
        }
        return [$foto, $documento];
    }
}

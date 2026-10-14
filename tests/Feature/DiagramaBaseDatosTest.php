<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La base de datos debe coincidir con el diagrama ER (documentacion\Diagramas
 * de SIGA.svg). Regla de Josmy (13/10/2026): si no viene en el diagrama, no se
 * toma. No hay columnas ni tablas de negocio fuera de él.
 */
class DiagramaBaseDatosTest extends TestCase
{
    use RefreshDatabase;

    private const COMUNES = ['id', 'created_at', 'updated_at'];

    /** Columnas de cada tabla según el diagrama (sin id ni marcas de tiempo). */
    private const DIAGRAMA = [
        'departamentos' => ['nombre'],
        'municipios' => ['nombre', 'departamento_id'],
        'distritos' => ['nombre', 'municipio_id'],
        'discapacidades' => ['nombre'],
        'expedientes' => ['discapacidad_id', 'matricula_id'],
        'enfermedades' => ['nombre', 'medicamento'],
        'notas_anuales' => ['matricula_id', 'promedio_anual', 'estado'],
        'padres' => ['nombre', 'dui', 'telefono', 'email', 'user_id'],
        'parientes' => ['padre_id', 'estudiante_id', 'parentesco'],
        'estudiantes' => ['nombre', 'fecha_nacimiento', 'genero', 'direccion', 'nie', 'canton', 'distrito_id', 'estado', 'user_id'],
        'encargados' => ['nombre', 'dui', 'telefono', 'direccion', 'parentesco', 'user_id'],
        'matriculas' => ['ingreso', 'estudiante_id', 'encargado_id', 'enfermedad_id', 'seccion_id', 'especialidad_id', 'es_trasladado', 'estado', 'foto', 'documento', 'anio_lectivo_id'],
        'especialidades' => ['nombre'],
        'plan_estudios' => ['nombre', 'especialidad_id', 'estado', 'fecha_inicio', 'fecha_fin', 'descripcion', 'fecha_aprobacion'],
        'asignaturas' => ['nombre', 'plan_estudio_id'],
        'anios_lectivos' => ['nombre', 'fecha_inicio', 'fecha_fin', 'estado'],
        'secciones' => ['nombre', 'profesor_id', 'grado_id', 'capacidad', 'turno', 'anio_lectivo_id'],
        'grados' => ['nombre'],
        'profesores' => ['nombre', 'fecha_nacimiento', 'codigo', 'telefono', 'dui', 'direccion', 'user_id'],
        'asignaciones' => ['profesor_id', 'asignatura_id', 'seccion_id'],
        'calificaciones' => ['nota_obtenida', 'evaluacion_id', 'matricula_id'],
        'evaluaciones' => ['nombre', 'porcentaje', 'periodo_escolar_id', 'asignacion_id'],
        'periodo_escolares' => ['nombre', 'nivel', 'anio_lectivo_id'],
        'conductas' => ['matricula_id', 'descripcion', 'valoracion', 'periodo_escolar_id'],
        'boletas' => ['matricula_id', 'asignacion_id', 'periodo_escolar_id', 'promedio'],
        'users' => ['email', 'password', 'rol_id'],
        'roles' => ['nombre'],
    ];

    public function test_las_tablas_coinciden_con_el_diagrama(): void
    {
        foreach (self::DIAGRAMA as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}");
            $esperadas = array_merge(self::COMUNES, $columnas);
            $reales = Schema::getColumnListing($tabla);
            sort($esperadas);
            sort($reales);
            $this->assertSame($esperadas, $reales, "Las columnas de {$tabla} no coinciden con el diagrama");
        }
    }

    public function test_no_hay_tablas_de_negocio_fuera_del_diagrama(): void
    {
        // Tablas propias de Laravel (caché, colas, sesiones y control de migraciones)
        $infraestructura = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'sessions'];
        $tablas = array_column(Schema::getTables(Schema::getConnection()->getDatabaseName()), 'name');
        $sobran = array_values(array_diff($tablas, array_keys(self::DIAGRAMA), $infraestructura));

        $this->assertSame([], $sobran, 'Tablas que no están en el diagrama');
    }

    /** Tamaños indicados en el diagrama (se comprueban en MySQL 8). */
    private const TAMANOS = [
        'departamentos.nombre' => 'varchar(50)', 'municipios.nombre' => 'varchar(50)', 'distritos.nombre' => 'varchar(50)',
        'discapacidades.nombre' => 'varchar(50)', 'enfermedades.nombre' => 'varchar(50)', 'enfermedades.medicamento' => 'varchar(50)',
        'notas_anuales.promedio_anual' => 'decimal(4,2)',
        'padres.nombre' => 'varchar(80)', 'padres.dui' => 'varchar(10)', 'padres.telefono' => 'varchar(15)', 'padres.email' => 'varchar(100)',
        'estudiantes.nombre' => 'varchar(80)', 'estudiantes.direccion' => 'varchar(100)', 'estudiantes.nie' => 'varchar(10)', 'estudiantes.canton' => 'varchar(50)',
        'encargados.nombre' => 'varchar(80)', 'encargados.dui' => 'varchar(10)', 'encargados.telefono' => 'varchar(15)', 'encargados.direccion' => 'varchar(100)',
        'especialidades.nombre' => 'varchar(50)',
        'plan_estudios.nombre' => 'varchar(30)', 'plan_estudios.descripcion' => 'varchar(200)',
        'asignaturas.nombre' => 'varchar(50)', 'anios_lectivos.nombre' => 'varchar(50)',
        'secciones.nombre' => 'varchar(10)', 'grados.nombre' => 'varchar(50)',
        'profesores.nombre' => 'varchar(80)', 'profesores.codigo' => 'varchar(15)', 'profesores.telefono' => 'varchar(15)',
        'profesores.dui' => 'varchar(10)', 'profesores.direccion' => 'varchar(100)',
        'calificaciones.nota_obtenida' => 'decimal(4,2)',
        'evaluaciones.nombre' => 'varchar(50)', 'evaluaciones.porcentaje' => 'decimal(4,2)',
        'periodo_escolares.nombre' => 'varchar(50)',
        'conductas.descripcion' => 'varchar(100)', 'conductas.valoracion' => 'varchar(20)',
        'boletas.promedio' => 'decimal(4,2)',
        'anios_lectivos.estado' => "enum('activo','inactivo','cierre')",
        'encargados.parentesco' => "enum('Tío(a)','Abuelo(a)','Hermano(a)','Primo(a)')",
        'parientes.parentesco' => "enum('Padre','Madre')",
    ];

    public function test_tamanos_y_valores_como_el_diagrama(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Los tamaños se comprueban en MySQL 8.');
        }

        $distintos = [];
        foreach (self::TAMANOS as $campo => $tipo) {
            [$tabla, $columna] = explode('.', $campo);
            $real = collect(Schema::getColumns($tabla))->firstWhere('name', $columna)['type'] ?? null;
            if ($real !== $tipo) {
                $distintos[$campo] = $real;
            }
        }

        $this->assertSame([], $distintos, 'Columnas con un tamaño distinto al del diagrama');
    }
}

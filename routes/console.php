<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Grados fuera del catálogo oficial (App\Support\CatalogoGrados). Sin orden no se
// puede calcular la promoción de reingreso; se corrige con el nombre oficial.
Artisan::command('siga:grados-sin-orden', function () {
    $grados = \App\Models\Grado::ordenAcademico()->filter(fn ($g) => $g->orden === null)->values();

    if ($grados->isEmpty()) {
        $this->info('Todos los grados pertenecen al catálogo oficial.');
        return;
    }

    $this->table(['id', 'nombre', 'nombre oficial equivalente', 'secciones'], $grados->map(fn ($g) => [
        $g->id, $g->nombre, \App\Support\CatalogoGrados::equivalente($g->nombre) ?? '(ninguno)', $g->secciones()->count(),
    ]));
    $this->warn($grados->count().' grado(s) fuera del catálogo oficial (no participan en la promoción de reingreso).');
})->purpose('Lista los grados que no pertenecen al catálogo oficial');

// M2.2: datos anteriores al calendario escolar que requieren revisión manual
// (años sin fechas, periodos sin año o número, secciones y matrículas sin año
// lectivo o con años que no coinciden). Solo lee; no modifica nada.
Artisan::command('siga:diagnostico-calendario', function () {
    $secciones = [
        'anios_sin_fechas' => ['Años lectivos sin fechas (no pueden activarse)', ['id', 'anio', 'estado']],
        'anios_sin_periodos' => ['Años lectivos sin periodos (no pueden activarse)', ['id', 'anio', 'estado']],
        'periodos_sin_anio' => ['Periodos anteriores sin año lectivo o sin número (nivel)', ['id', 'nombre', 'nivel', 'anio_lectivo_id']],
        'secciones_sin_anio' => ['Secciones sin año lectivo', ['id', 'nombre', 'grado_id']],
        'matriculas_sin_anio' => ['Matrículas sin año lectivo', ['id', 'estudiante_id', 'seccion_id']],
        'matriculas_con_otro_anio_que_su_seccion' => ['Matrículas con un año lectivo distinto al de su sección', ['id', 'anio_lectivo_id', 'seccion_id']],
        'padres_sin_estudiantes' => ['Padres sin estudiantes vinculados (solo el director puede vincularlos)', ['id', 'nombre', 'dui']],
    ];

    $diagnostico = app(\App\Services\CalendarioService::class)->diagnostico();
    $total = 0;

    foreach ($secciones as $clave => [$titulo, $columnas]) {
        $filas = $diagnostico[$clave];
        $total += count($filas);
        $this->line('');
        $this->info($titulo.': '.count($filas));
        if (count($filas) > 0) {
            $this->table($columnas, array_map(fn ($fila) => array_map(fn ($c) => $fila[$c] ?? '(vacío)', $columnas), $filas));
        }
    }

    $this->line('');
    $total === 0
        ? $this->info('No hay registros pendientes de revisión.')
        : $this->warn("{$total} registro(s) requieren revisión manual. Nada se modificó.");
})->purpose('Lista los datos del calendario escolar que requieren intervención');

// M4: asignaciones que impiden el índice único (D-A2) o que no cumplen las reglas
// de pensum y de un plan por sección (D-A1). Solo lee; no modifica nada.
Artisan::command('siga:diagnostico-asignaciones', function () {
    $secciones = [
        'duplicadas' => ['Misma materia repetida en una sección (impide el índice único)', ['seccion_id', 'asignatura_id', 'total', 'primera_id']],
        'secciones_con_varios_planes' => ['Secciones con materias de más de un plan', ['seccion_id', 'planes']],
    ];

    $diagnostico = app(\App\Services\AsignacionService::class)->diagnostico();
    $total = 0;

    foreach ($secciones as $clave => [$titulo, $columnas]) {
        $filas = $diagnostico[$clave];
        $total += count($filas);
        $this->line('');
        $this->info($titulo.': '.count($filas));
        if (count($filas) > 0) {
            $this->table($columnas, array_map(fn ($fila) => array_map(fn ($c) => $fila[$c] ?? '(vacío)', $columnas), $filas));
        }
    }

    $this->line('');
    $total === 0
        ? $this->info('Las asignaciones cumplen las reglas de M4.')
        : $this->warn("{$total} caso(s) para revisar manualmente. No se modificó nada.");
})->purpose('Revisa las asignaciones existentes contra las reglas de M4');

// M5: datos familiares que no cumplen las reglas nuevas (una persona por DUI,
// un Padre y una Madre por estudiante, NIE numérico). Solo lee; no modifica nada.
Artisan::command('siga:diagnostico-familias', function () {
    $secciones = [
        'padres_dui_repetido' => ['Padres o madres con el mismo DUI', ['tabla', 'dui', 'ids']],
        'encargados_dui_repetido' => ['Encargados con el mismo DUI', ['tabla', 'dui', 'ids']],
        'vinculos_repetidos' => ['Mismo padre vinculado dos veces al estudiante', ['estudiante_id', 'padre_id', 'total']],
        'parentesco_repetido' => ['Estudiantes con más de un Padre o más de una Madre', ['estudiante_id', 'parentesco', 'total']],
        'nie_no_valido' => ['NIE con formato no válido (solo números, hasta 10)', ['id', 'nombre', 'nie']],
    ];

    $servicio = app(\App\Services\DiagnosticoFamiliasService::class);
    $diagnostico = $servicio->ejecutar();
    $total = 0;

    foreach ($secciones as $clave => [$titulo, $columnas]) {
        $filas = $diagnostico[$clave];
        $total += count($filas);
        $this->line('');
        $this->info($titulo.': '.count($filas));
        if (count($filas) > 0) {
            $this->table($columnas, array_map(fn ($fila) => array_map(fn ($c) => $fila[$c] ?? '(vacío)', $columnas), $filas));
        }
    }

    $this->line('');
    $this->line('Informativo: padres sin cuenta ni estudiantes (solo la dirección los vincula): '.$servicio->padresSinVinculo());
    $total === 0
        ? $this->info('Los datos familiares cumplen las reglas de M5.')
        : $this->warn("{$total} caso(s) para revisar manualmente. No se modificó nada.");
})->purpose('Revisa estudiantes, padres y encargados contra las reglas de M5');

// M6.1: matrículas existentes que no cumplen las reglas nuevas. Solo lee; no modifica nada.
Artisan::command('siga:diagnostico-matriculas', function () {
    $comunes = ['id', 'estudiante_id', 'anio_lectivo_id', 'seccion_id', 'estado'];
    $secciones = [
        'duplicadas' => ['Estudiantes con más de una matrícula pendiente o aprobada en el mismo año', ['estudiante_id', 'anio_lectivo_id', 'total']],
        'secciones_sobre_cupo' => ['Secciones con más matrículas vigentes que su capacidad', ['id', 'nombre', 'capacidad', 'ocupados']],
        'aprobadas_sin_seccion' => ['Matrículas aprobadas sin sección', $comunes],
        'seccion_de_otro_anio' => ['Matrículas vigentes con una sección de otro año lectivo', $comunes],
        'especialidad_distinta_del_plan' => ['Matrículas vigentes cuya especialidad no coincide con el plan de su sección', [...$comunes, 'especialidad_id', 'plan']],
        'sin_responsable' => ['Matrículas vigentes sin responsable (sin encargado y la cuenta no es padre o madre del estudiante)', $comunes],
        'archivos_publicos' => ['Matrículas con foto o documento aún en el disco público', ['id', 'estudiante_id', 'foto', 'documento']],
        'archivos_faltantes' => ['Archivos privados que no existen en el disco', ['id', 'estudiante_id', 'archivo', 'ruta']],
    ];

    $servicio = app(\App\Services\DiagnosticoMatriculasService::class);
    $diagnostico = $servicio->ejecutar();
    $total = 0;

    foreach ($secciones as $clave => [$titulo, $columnas]) {
        $filas = $diagnostico[$clave];
        $total += count($filas);
        $this->line('');
        $this->info($titulo.': '.count($filas));
        if (count($filas) > 0) {
            $this->table($columnas, array_map(fn ($fila) => array_map(fn ($c) => $fila[$c] ?? '(vacío)', $columnas), $filas));
        }
    }

    $this->line('');
    $this->line('Informativo: matrículas pendientes sin sección (la dirección la asigna al aprobar): '.$servicio->pendientesSinSeccion());
    $total === 0
        ? $this->info('Las matrículas cumplen las reglas de M6.1.')
        : $this->warn("{$total} caso(s) para revisar manualmente. No se modificó nada.");
})->purpose('Revisa las matrículas existentes contra las reglas de M6.1');

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// M1: cuentas históricas que quedaron sin nombre o DUI tras la migración de users.
// Deben completarse antes de volver obligatorias esas columnas en la base de datos.
Artisan::command('siga:usuarios-sin-dui', function () {
    $usuarios = \App\Models\User::whereNull('dui')->orWhereNull('nombre')->orderBy('id')->get();

    if ($usuarios->isEmpty()) {
        $this->info('Todas las cuentas tienen nombre y DUI.');
        return;
    }

    $this->table(
        ['id', 'email', 'nombre', 'dui', 'estado', 'rol'],
        $usuarios->map(fn ($u) => [$u->id, $u->email, $u->nombre ?? '(falta)', $u->dui ?? '(falta)', $u->estado, $u->rol() ?? '(sin rol)'])
    );
    $this->warn($usuarios->count().' cuenta(s) requieren intervención manual.');
})->purpose('Lista las cuentas sin nombre o DUI que requieren intervención');

// M2.1.1: grados sin orden académico. Sin orden no se puede calcular la promoción
// de reingreso; el director lo completa al editar el grado.
Artisan::command('siga:grados-sin-orden', function () {
    $grados = \App\Models\Grado::whereNull('orden')->orderBy('nombre')->get();

    if ($grados->isEmpty()) {
        $this->info('Todos los grados tienen orden académico.');
        return;
    }

    $this->table(['id', 'nombre', 'secciones'], $grados->map(fn ($g) => [$g->id, $g->nombre, $g->secciones()->count()]));
    $this->warn($grados->count().' grado(s) requieren que el director asigne el orden académico.');
})->purpose('Lista los grados sin orden académico que requieren intervención');

// M2.2: datos anteriores al calendario escolar que requieren revisión manual
// (años sin fechas, periodos sin año o número, secciones y matrículas sin año
// lectivo o con años que no coinciden). Solo lee; no modifica nada.
Artisan::command('siga:diagnostico-calendario', function () {
    $secciones = [
        'anios_sin_fechas' => ['Años lectivos sin fechas (no pueden activarse)', ['id', 'nombre', 'estado']],
        'anios_sin_periodos' => ['Años lectivos sin periodos', ['id', 'nombre', 'estado']],
        'periodos_sin_anio' => ['Periodos anteriores sin año lectivo o sin número (se conservan nombre y nivel)', ['id', 'nombre', 'nivel', 'anio_lectivo_id', 'numero']],
        'secciones_sin_anio' => ['Secciones sin año lectivo', ['id', 'nombre', 'anio', 'grado_id']],
        'matriculas_sin_anio' => ['Matrículas sin año lectivo (año inválido o distinto al de su sección)', ['id', 'anio', 'estudiante_id', 'seccion_id']],
        'matriculas_con_otro_anio_que_su_seccion' => ['Matrículas con un año lectivo distinto al de su sección', ['id', 'anio', 'anio_lectivo_id', 'seccion_id']],
        'anios_solo_en_asignaciones' => ['Años que solo aparecen en asignaciones (sin sección ni matrícula de ese año)', ['anio', 'anio_lectivo_id', 'estado', 'asignaciones']],
        'asignaciones_sin_anio_lectivo' => ['Asignaciones cuyo año no corresponde a ningún año lectivo', ['id', 'anio', 'seccion_id']],
        'asignaciones_con_otro_anio_que_su_seccion' => ['Asignaciones cuyo año no coincide con el de su sección', ['id', 'anio', 'seccion_id', 'anio_seccion']],
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

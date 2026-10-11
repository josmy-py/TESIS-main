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

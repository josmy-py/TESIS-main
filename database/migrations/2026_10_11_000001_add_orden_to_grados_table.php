<?php

use App\Support\OrdenGrado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * M2.1.1: orden académico de los grados.
 *
 * La promoción usaba grado_id + 1, que depende del orden de inserción. Esta
 * migración agrega grados.orden (entero positivo, único, nulo permitido para
 * los grados existentes) y lo completa solo cuando el nombre no deja dudas
 * (regla en App\Support\OrdenGrado). Nunca se deduce del id. Si dos grados
 * apuntan al mismo orden, ninguno lo recibe. Los que quedan sin orden se
 * listan con: php artisan siga:grados-sin-orden
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grados', function (Blueprint $table) {
            $table->unsignedInteger('orden')->nullable()->unique()->after('nombre');
        });

        $propuestos = DB::table('grados')->orderBy('id')->get(['id', 'nombre'])
            ->map(fn ($grado) => ['id' => $grado->id, 'orden' => OrdenGrado::inferir($grado->nombre)])
            ->filter(fn ($grado) => $grado['orden'] !== null);

        $repetidos = $propuestos->countBy('orden')->filter(fn ($veces) => $veces > 1)->keys();

        foreach ($propuestos->reject(fn ($grado) => $repetidos->contains($grado['orden'])) as $grado) {
            DB::table('grados')->where('id', $grado['id'])->update(['orden' => $grado['orden']]);
        }

        $pendientes = DB::table('grados')->whereNull('orden')->count();
        if ($pendientes > 0) {
            Log::warning("M2.1: {$pendientes} grado(s) quedaron sin orden académico. Revíselos con: php artisan siga:grados-sin-orden");
        }
    }

    public function down(): void
    {
        Schema::table('grados', function (Blueprint $table) {
            $table->dropUnique(['orden']);
            $table->dropColumn('orden');
        });
    }
};

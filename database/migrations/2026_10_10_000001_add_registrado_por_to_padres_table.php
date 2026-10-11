<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre de M1: cuenta que registró cada padre.
 *
 * Un padre sin cuenta propia (user_id) y sin estudiantes no tenía ningún dato
 * que indicara a quién pertenece, y cualquier ENCARGADO podía vincularlo.
 * Con registrado_por solo la cuenta que lo registró puede vincularlo.
 *
 * - Columna nullable: los padres que ya existen quedan con NULL porque no hay
 *   forma de saber quién los registró; no se inventa el dato.
 * - Un padre existente con user_id o ya vinculado a estudiantes sigue
 *   funcionando igual (su propiedad sale de esos datos).
 * - Un padre existente sin cuenta, sin estudiantes y sin registrado_por queda
 *   bloqueado para los encargados; solo el DIRECTOR puede vincularlo.
 * - Si se elimina la cuenta, el padre queda con NULL (mismo bloqueo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('padres', function (Blueprint $table) {
            $table->foreignId('registrado_por')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('padres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registrado_por');
        });
    }
};

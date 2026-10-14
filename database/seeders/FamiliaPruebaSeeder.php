<?php

namespace Database\Seeders;

use App\Models\Distrito;
use App\Models\Estudiante;
use App\Models\Padre;
use App\Models\Pariente;
use App\Models\User;
use Illuminate\Database\Seeder;

class FamiliaPruebaSeeder extends Seeder
{
    /**
     * Familia de ejemplo (M5) para encargado@siga.test: la cuenta es el padre de
     * dos estudiantes (uno con NIE y otro sin él) y la madre se registra sin cuenta.
     * Respeta D3: una persona por DUI y un Padre y una Madre por estudiante.
     * Idempotente. No se ejecuta en producción. Necesita la geografía (distritos).
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('FamiliaPruebaSeeder no se ejecuta en producción.');
            return;
        }

        $cuenta = User::where('email', 'encargado@siga.test')->first();
        $distrito = Distrito::orderBy('id')->first();
        if (! $cuenta || ! $distrito) {
            $this->command?->warn('FamiliaPruebaSeeder: falta la cuenta encargado@siga.test o la geografía; no se creó la familia.');
            return;
        }

        $padre = Padre::firstOrCreate(['dui' => '00000002-6'], ['nombre' => 'Encargado de Prueba', 'telefono' => '7000-0002', 'email' => $cuenta->email]);
        if ($padre->user_id === null && ! Padre::where('user_id', $cuenta->id)->exists()) {
            $padre->update(['user_id' => $cuenta->id]);
        }
        $madre = Padre::firstOrCreate(['dui' => '00000020-4'], ['nombre' => 'Ana Gómez de Pérez', 'telefono' => '7777-0020', 'email' => null]);

        $estudiantes = [
            ['nombre' => 'Luis Pérez Gómez', 'fecha_nacimiento' => '2015-03-10', 'genero' => 'Masculino', 'nie' => '1234567'],
            ['nombre' => 'María Pérez Gómez', 'fecha_nacimiento' => '2017-07-21', 'genero' => 'Femenino', 'nie' => null],
        ];

        foreach ($estudiantes as $datos) {
            $estudiante = Estudiante::firstOrCreate(
                ['nombre' => $datos['nombre'], 'fecha_nacimiento' => $datos['fecha_nacimiento'], 'user_id' => $cuenta->id],
                $datos + ['distrito_id' => $distrito->id, 'direccion' => 'Colonia Las Flores, casa 5', 'canton' => 'El Tule', 'estado' => 'inscrito']
            );

            foreach ([[$padre, 'Padre'], [$madre, 'Madre']] as [$persona, $parentesco]) {
                $ocupado = Pariente::where('estudiante_id', $estudiante->id)->where('parentesco', $parentesco)->exists();
                if (! $ocupado && ! Pariente::where('estudiante_id', $estudiante->id)->where('padre_id', $persona->id)->exists()) {
                    Pariente::create(['estudiante_id' => $estudiante->id, 'padre_id' => $persona->id, 'parentesco' => $parentesco]);
                }
            }
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Estudiante;
use App\Models\Grado;
use App\Models\Matricula;
use App\Models\Seccion;
use App\Models\User;
use App\Services\MatriculaService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class MatriculaPruebaSeeder extends Seeder
{
    /** Estudiante de FamiliaPruebaSeeder => grado de su sección de SeccionPruebaSeeder. */
    public const SOLICITUDES = [
        'María Pérez Gómez' => 'Primer grado',
        'Luis Pérez Gómez' => 'Segundo grado',
    ];

    /**
     * Solicitudes de matrícula de ejemplo (M6.1): una pendiente de Nuevo ingreso
     * por estudiante de encargado@siga.test, con foto y partida generadas en el
     * disco privado. Pasa por MatriculaService, así que cumple todas las reglas
     * (promoción y responsable). Como las llena el encargado, quedan sin
     * sección: la asigna la dirección al aprobar. Idempotente: no crea otra si el
     * estudiante ya tiene una matrícula vigente en el año. No se ejecuta en
     * producción. Necesita FamiliaPruebaSeeder, SeccionPruebaSeeder y
     * AsignacionPruebaSeeder.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('MatriculaPruebaSeeder no se ejecuta en producción.');
            return;
        }

        $cuenta = User::where('email', 'encargado@siga.test')->first();
        if (! $cuenta) {
            $this->command?->warn('MatriculaPruebaSeeder: falta la cuenta encargado@siga.test.');
            return;
        }

        $servicio = app(MatriculaService::class);

        foreach (self::SOLICITUDES as $nombre => $grado) {
            $estudiante = Estudiante::where('user_id', $cuenta->id)->where('nombre', $nombre)->first();
            $gradoId = Grado::where('nombre', $grado)->value('id');
            $seccion = $gradoId ? Seccion::where('grado_id', $gradoId)->where('nombre', 'A')->orderByDesc('anio_lectivo_id')->first() : null;
            if (! $estudiante || ! $seccion) {
                $this->command?->warn("MatriculaPruebaSeeder: falta {$nombre} o la sección {$grado} A; se omite.");
                continue;
            }

            $existe = Matricula::where('estudiante_id', $estudiante->id)->where('anio_lectivo_id', $seccion->anio_lectivo_id)
                ->whereIn('estado', MatriculaService::VIGENTES)->exists();
            if ($existe) {
                continue;
            }

            try {
                $servicio->crear([
                    'ingreso' => 'Nuevo ingreso',
                    'estudiante_id' => $estudiante->id,
                    'anio_lectivo_id' => $seccion->anio_lectivo_id,
                    'es_trasladado' => false,
                ], $this->archivo('foto.png', $this->png()), $this->archivo('partida.pdf', $this->pdf($nombre)), $cuenta, $cuenta->id);
            } catch (ValidationException $e) {
                $this->command?->warn("MatriculaPruebaSeeder: no se creó la matrícula de {$nombre}: ".collect($e->errors())->flatten()->implode(' '));
            } catch (\DomainException $e) {
                // Por ejemplo, fuera del periodo de matrícula del año
                $this->command?->warn("MatriculaPruebaSeeder: no se creó la matrícula de {$nombre}: {$e->getMessage()}");
            }
        }
    }

    private function archivo(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'siga');
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    /** PNG gris de 1x1 (no necesita la extensión GD). */
    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNoAAAAggCB3UNq9AAAAABJRU5ErkJggg==');
    }

    private function pdf(string $nombre): string
    {
        $texto = "Partida de nacimiento de prueba: {$nombre}";
        $contenido = "BT /F1 12 Tf 50 750 Td ({$texto}) Tj ET";

        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            .'4 0 obj<</Length '.strlen($contenido).">>stream\n{$contenido}\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}

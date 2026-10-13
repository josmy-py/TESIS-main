<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * M6.1 (D-M9): la foto y el documento de cada matrícula pasan del disco
 * público (`/storage/foto/...`, `/storage/documentos/...`, visibles para
 * cualquiera con la URL) al disco privado `matriculas`, que solo se descarga
 * por la API con sesión. No cambia la estructura de la base: solo la ruta guardada.
 *
 * Cada archivo se copia, se comprueba que la copia sea idéntica y solo
 * entonces se actualiza la ruta y se borra la copia pública. Un archivo que no
 * existe en el disco se deja como está (lo informa siga:diagnostico-matriculas).
 * La lista de traslados queda en storage/app/respaldos. Revertir devuelve
 * cada archivo a su carpeta pública y su ruta anterior.
 */
return new class extends Migration
{
    // carpeta pública => carpeta privada
    private const CARPETAS = ['foto' => 'fotos', 'documentos' => 'documentos'];

    public function up(): void
    {
        $publico = Storage::disk('public');
        $privado = Storage::disk('matriculas');
        $movidos = [];
        $faltantes = [];

        foreach (DB::table('matriculas')->orderBy('id')->get(['id', 'foto', 'documento']) as $fila) {
            foreach (['foto', 'documento'] as $campo) {
                $ruta = $fila->{$campo};
                if (! $ruta || ! str_starts_with($ruta, '/storage/')) {
                    continue;
                }

                $relativa = substr($ruta, strlen('/storage/'));
                [$carpeta] = explode('/', $relativa, 2) + [1 => ''];
                if (! isset(self::CARPETAS[$carpeta]) || ! $publico->exists($relativa)) {
                    $faltantes[] = ['matricula_id' => $fila->id, 'campo' => $campo, 'ruta' => $ruta];
                    continue;
                }

                $nueva = self::CARPETAS[$carpeta].'/'.basename($relativa);
                $contenido = $publico->get($relativa);
                $privado->put($nueva, $contenido);
                if (md5((string) $privado->get($nueva)) !== md5($contenido)) {
                    $privado->delete($nueva);
                    throw new RuntimeException("No se pudo copiar el archivo {$ruta} de la matrícula {$fila->id}; no se modificó su ruta.");
                }

                DB::table('matriculas')->where('id', $fila->id)->update([$campo => $nueva]);
                $publico->delete($relativa);
                $movidos[] = ['matricula_id' => $fila->id, 'campo' => $campo, 'anterior' => $ruta, 'nueva' => $nueva];
            }
        }

        if ($movidos || $faltantes) {
            File::ensureDirectoryExists(RespaldoAlineacion::directorio());
            File::put(
                RespaldoAlineacion::directorio().'/m6-archivos-matriculas-'.date('Ymd-His').'.json',
                json_encode(['creado' => now()->toDateTimeString(), 'movidos' => $movidos, 'faltantes' => $faltantes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }

    public function down(): void
    {
        $publico = Storage::disk('public');
        $privado = Storage::disk('matriculas');
        $carpetas = array_flip(self::CARPETAS);

        foreach (DB::table('matriculas')->orderBy('id')->get(['id', 'foto', 'documento']) as $fila) {
            foreach (['foto', 'documento'] as $campo) {
                $ruta = $fila->{$campo};
                if (! $ruta || str_starts_with($ruta, '/storage/') || ! $privado->exists($ruta)) {
                    continue;
                }

                [$carpeta] = explode('/', $ruta, 2);
                $relativa = ($carpetas[$carpeta] ?? $carpeta).'/'.basename($ruta);
                $publico->put($relativa, $privado->get($ruta));
                DB::table('matriculas')->where('id', $fila->id)->update([$campo => '/storage/'.$relativa]);
                $privado->delete($ruta);
            }
        }
    }
};

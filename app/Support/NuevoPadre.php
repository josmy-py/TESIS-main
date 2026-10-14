<?php

namespace App\Support;

use App\Models\Encargado;
use App\Models\Padre;
use App\Models\User;
use App\Rules\Dui;
use App\Services\UsuarioService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Datos de un padre o una madre nuevos (tabla padres: nombre, dui, telefono,
 * email, user_id). El encargado lo registra antes que al estudiante, sin
 * vínculo; la relación se crea al guardar al estudiante, buscándolo por DUI.
 *
 * es_responsable: la persona es la titular de la cuenta ENCARGADO que registra
 * (padres.user_id = cuenta); así queda como responsable de sus estudiantes en
 * la matrícula (D-M1). Una cuenta solo tiene un responsable.
 */
class NuevoPadre
{
    /** Nombre de persona: solo letras (con tildes y ñ) y espacios. */
    public const REGLA_NOMBRE = 'regex:/^[\pL ]+$/u';

    public const MENSAJE_NOMBRE = 'El nombre solo puede tener letras y espacios.';

    /** Quita espacios sobrantes del nombre antes de validarlo. */
    public static function limpiarNombre(?string $nombre): ?string
    {
        return $nombre === null ? null : preg_replace('/\s+/u', ' ', trim($nombre));
    }

    /**
     * Valida y devuelve los datos listos para PadreService::crear().
     * $prefijo: para los errores de una lista (por ejemplo "padres.0.").
     */
    public static function validar(array $datos, User $cuenta, string $prefijo = ''): array
    {
        $datos['nombre'] = self::limpiarNombre($datos['nombre'] ?? null);
        $datos['dui'] = DuiFormato::normalizar($datos['dui'] ?? null);
        if (! array_key_exists('es_responsable', $datos) && array_key_exists('es_encargado', $datos)) {
            $datos['es_responsable'] = $datos['es_encargado'];
        }

        $validador = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:80', self::REGLA_NOMBRE],
            'dui' => ['required', new Dui, 'unique:padres,dui'],
            'telefono' => 'nullable|string|max:15',
            'email' => 'nullable|email|max:100',
            'es_responsable' => 'required|boolean',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.regex' => self::MENSAJE_NOMBRE,
            'dui.required' => 'El DUI es obligatorio.',
            'dui.unique' => 'Este DUI ya está registrado. Si es de su familia, ya aparece en su lista; si no, para vincular a esta persona con su estudiante se necesita la autorización de la dirección.',
            'email.email' => 'El correo no tiene un formato válido.',
            'es_responsable.required' => 'Indique si será el responsable del estudiante.',
        ]);

        if ($validador->fails()) {
            $errores = [];
            foreach ($validador->errors()->messages() as $campo => $mensajes) {
                $errores[$prefijo.$campo] = $mensajes;
            }
            throw ValidationException::withMessages($errores);
        }

        $validos = $validador->validated();
        $userId = null;
        if ($validos['es_responsable']) {
            self::verificarResponsable($cuenta);
            $userId = $cuenta->id;
        }

        return [
            'nombre' => $validos['nombre'],
            'dui' => $validos['dui'],
            'telefono' => $validos['telefono'] ?? null,
            'email' => $validos['email'] ?? null,
            'user_id' => $userId,
        ];
    }

    /** Solo una cuenta ENCARGADO sin otro responsable puede marcar a la persona como responsable (409 si no). */
    public static function verificarResponsable(User $cuenta): void
    {
        app(UsuarioService::class)->verificarRol($cuenta, User::ROL_ENCARGADO);

        if (Encargado::where('user_id', $cuenta->id)->exists()) {
            throw new \DomainException('Su cuenta ya tiene un perfil de encargado: el responsable de sus estudiantes es ese perfil.');
        }
        if (Padre::where('user_id', $cuenta->id)->exists()) {
            throw new \DomainException('Su cuenta ya tiene un padre o madre responsable registrado.');
        }
    }
}

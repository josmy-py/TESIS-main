<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla roles del diagrama (id, nombre). SIGA usa tres: DIRECTOR, DOCENTE y ENCARGADO.
 */
class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = ['nombre'];

    public function users()
    {
        return $this->hasMany(User::class, 'rol_id');
    }

    public static function idDe(string $nombre): int
    {
        return (int) static::where('nombre', $nombre)->valueOrFail('id');
    }
}

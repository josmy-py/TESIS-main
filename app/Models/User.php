<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Tabla users del diagrama: email, password y rol_id (tabla roles).
 */
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    //implementación de los métodos de JWT
    public function getJWTIdentifier(){
        return $this->getKey();
    }
    public function getJWTCustomClaims(){
        return [self::CLAIM_CONTRASENA => $this->huellaContrasena()];
    }

    // Claim del JWT con la huella de la contraseña vigente al emitir el token
    public const CLAIM_CONTRASENA = 'hpw';

    /**
     * Huella de la contraseña actual: HMAC del hash con APP_KEY, recortado.
     * No permite recuperar la contraseña ni el hash. Cambia cada vez que se
     * cambia la contraseña, y con ella dejan de servir los tokens emitidos
     * antes (middleware SesionVigente).
     */
    public function huellaContrasena(): string
    {
        return substr(hash_hmac('sha256', (string) $this->password, (string) config('app.key')), 0, 32);
    }

    // rol_id no es asignable en masa: solo se cambia con asignarRol()
    protected $fillable = [
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // Roles de SIGA (tabla roles). Un único rol por usuario.
    public const ROL_DIRECTOR = 'DIRECTOR';
    public const ROL_DOCENTE = 'DOCENTE';
    public const ROL_ENCARGADO = 'ENCARGADO';
    public const ROLES = [self::ROL_DIRECTOR, self::ROL_DOCENTE, self::ROL_ENCARGADO];

    public function rolAsignado()
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function rol(): ?string
    {
        return $this->rolAsignado?->nombre;
    }

    /** Acepta un nombre, varios separados por "|" o un arreglo. */
    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : explode('|', $roles);

        return in_array($this->rol(), $roles, true);
    }

    /** asignarRol del diagrama de clases. No guarda: el llamador hace save(). */
    public function asignarRol(string $rol): void
    {
        $this->rol_id = Rol::idDe($rol);
        $this->unsetRelation('rolAsignado');
    }

    public function scopeConRol(Builder $query, string $rol): Builder
    {
        return $query->whereHas('rolAsignado', fn ($q) => $q->where('nombre', $rol));
    }

    public function esDirector(): bool
    {
        return $this->hasRole(self::ROL_DIRECTOR);
    }

    // Datos que la API devuelve del usuario (sin contraseña)
    public function datosSesion(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'rol' => $this->rol(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    public function profesor()
    {
        return $this->hasOne(Profesor::class);
    }

    public function padre()
    {
        return $this->hasOne(Padre::class);
    }

    public function encargado()
    {
        return $this->hasOne(Encargado::class);
    }

    public function estudiantes()
    {
        return $this->hasMany(Estudiante::class);
    }
}

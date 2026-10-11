<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
      /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    //sobreescribimos la variable $guard_name
    protected $guard_name = 'api';

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
     * cambia o restablece la contraseña, y con ella dejan de servir los
     * tokens emitidos antes (middleware SesionVigente).
     */
    public function huellaContrasena(): string
    {
        return substr(hash_hmac('sha256', (string) $this->password, (string) config('app.key')), 0, 32);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    // estado, email_verified_at y los roles no son asignables en masa:
    // solo se cambian de forma explícita (registro, activación, gestión del director)
    protected $fillable = [
        'nombre',
        'dui',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Roles técnicos de SIGA (Spatie, guard api). Un único rol por usuario.
    public const ROL_DIRECTOR = 'DIRECTOR';
    public const ROL_DOCENTE = 'DOCENTE';
    public const ROL_ENCARGADO = 'ENCARGADO';
    public const ROLES = [self::ROL_DIRECTOR, self::ROL_DOCENTE, self::ROL_ENCARGADO];

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_INACTIVO = 'inactivo';

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    public function esDirector(): bool
    {
        return $this->hasRole(self::ROL_DIRECTOR);
    }

    public function rol(): ?string
    {
        return $this->getRoleNames()->first();
    }

    // Datos que la API devuelve del usuario (sin contraseña ni tokens)
    public function datosSesion(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'dui' => $this->dui,
            'email' => $this->email,
            'estado' => $this->estado,
            'email_verificado' => $this->email_verified_at !== null,
            'rol' => $this->rol(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    // Enlace de recuperación hacia el frontend (broker de Laravel)
    public function sendPasswordResetNotification($token): void
    {
        $url = app(\App\Services\UsuarioService::class)->urlContrasena($this, $token);

        $this->notify(new \App\Notifications\RestablecerContrasena(
            $url,
            (int) config('auth.passwords.users.expire', 60)
        ));
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

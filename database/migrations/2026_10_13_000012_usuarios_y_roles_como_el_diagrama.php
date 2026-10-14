<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solo el diagrama (13/10/2026): users(email, password, rol_id) y roles(nombre).
 *
 * - Las tablas del paquete de roles (roles con name/guard_name, model_has_roles,
 *   permissions, model_has_permissions, role_has_permissions) se reemplazan por
 *   la tabla roles del diagrama. Cada cuenta conserva su rol en users.rol_id.
 * - Se quitan de users: nombre, dui, estado, email_verified_at y remember_token
 *   (activación, recuperación y desactivación de cuentas ya no existen).
 * - Se quitan password_reset_tokens (recuperación por correo) y notifications (avisos).
 *
 * Lo que se quita queda en storage/app/respaldos/ (App\Support\RespaldoAlineacion);
 * revertir la migración lo devuelve.
 */
return new class extends Migration
{
    // SQLite no permite suspender las claves foráneas dentro de una transacción
    public $withinTransaction = false;

    private const RESPALDO = 'usuarios-roles';

    private const ROLES = ['DIRECTOR', 'DOCENTE', 'ENCARGADO'];

    private const TABLAS_PAQUETE = ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];

    public function up(): void
    {
        $rolDeCuenta = $this->rolesActuales();

        RespaldoAlineacion::guardar(self::RESPALDO, [
            'users' => RespaldoAlineacion::filas(DB::table('users'), ['id', 'nombre', 'dui', 'estado', 'email_verified_at', 'remember_token']),
            'roles_de_cuenta' => collect($rolDeCuenta)->map(fn ($rol, $id) => ['user_id' => $id, 'rol' => $rol])->values()->all(),
            'notifications' => Schema::hasTable('notifications') ? DB::table('notifications')->orderBy('created_at')->get()->map(fn ($f) => (array) $f)->all() : [],
            'paquete' => $this->tablasDelPaquete(),
        ]);

        Schema::withoutForeignKeyConstraints(function () use ($rolDeCuenta) {
            foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles'] as $tabla) {
                Schema::dropIfExists($tabla);
            }

            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 50)->unique();
                $table->timestamps();
            });
            foreach (self::ROLES as $rol) {
                DB::table('roles')->insert(['nombre' => $rol, 'created_at' => now(), 'updated_at' => now()]);
            }
            $ids = DB::table('roles')->pluck('id', 'nombre');

            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('rol_id')->nullable()->after('password')->constrained('roles');
            });
            foreach ($rolDeCuenta as $userId => $rol) {
                if (isset($ids[$rol])) {
                    DB::table('users')->where('id', $userId)->update(['rol_id' => $ids[$rol]]);
                }
            }

            if (Schema::hasIndex('users', ['dui'], 'unique')) {
                Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['dui']));
            }
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['nombre', 'dui', 'estado', 'email_verified_at', 'remember_token']);
            });

            Schema::dropIfExists('password_reset_tokens');
            Schema::dropIfExists('notifications');
        });
    }

    public function down(): void
    {
        $respaldo = RespaldoAlineacion::leer(self::RESPALDO);

        Schema::withoutForeignKeyConstraints(function () use ($respaldo) {
            $roles = DB::table('roles')->pluck('nombre', 'id');
            $rolDeCuenta = DB::table('users')->whereNotNull('rol_id')->pluck('rol_id', 'id')
                ->map(fn ($rolId) => $roles[$rolId] ?? null)->filter()->all();

            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['rol_id']);
                $table->dropColumn('rol_id');
            });
            Schema::table('users', function (Blueprint $table) {
                $table->string('nombre', 80)->nullable()->after('id');
                $table->string('dui', 10)->nullable()->unique()->after('nombre');
                $table->timestamp('email_verified_at')->nullable()->after('email');
                $table->enum('estado', ['pendiente', 'activo', 'inactivo'])->default('activo')->after('password');
                $table->rememberToken();
            });
            foreach ($respaldo['users'] ?? [] as $fila) {
                DB::table('users')->where('id', $fila['id'])->update(collect($fila)->except('id')->all());
            }

            Schema::drop('roles');
            Schema::create('permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            Schema::create('roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
                $table->primary(['permission_id', 'model_id', 'model_type']);
            });
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            // Las tablas del paquete vuelven tal como estaban; las cuentas creadas después reciben su rol
            foreach (self::TABLAS_PAQUETE as $tabla) {
                foreach ($respaldo['paquete'][$tabla] ?? [] as $fila) {
                    DB::table($tabla)->insert($fila);
                }
            }
            foreach (self::ROLES as $rol) {
                if (! DB::table('roles')->where('name', $rol)->exists()) {
                    DB::table('roles')->insert(['name' => $rol, 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $ids = DB::table('roles')->pluck('id', 'name');
            $conRol = DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->pluck('model_id')->all();
            foreach ($rolDeCuenta as $userId => $rol) {
                if (! in_array($userId, $conRol)) {
                    DB::table('model_has_roles')->insert(['role_id' => $ids[$rol], 'model_type' => 'App\\Models\\User', 'model_id' => $userId]);
                }
            }

            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
            foreach ($respaldo['notifications'] ?? [] as $fila) {
                DB::table('notifications')->insert($fila);
            }
        });

        RespaldoAlineacion::archivar(self::RESPALDO);
    }

    /** Filas de las tablas del paquete anterior, para devolverlas al revertir. */
    private function tablasDelPaquete(): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasColumn('roles', 'name')) {
            return [];
        }

        return collect(self::TABLAS_PAQUETE)->mapWithKeys(fn ($tabla) => [
            $tabla => DB::table($tabla)->get()->map(fn ($f) => (array) $f)->all(),
        ])->all();
    }

    /** Rol actual de cada cuenta según las tablas del paquete anterior: [user_id => nombre]. */
    private function rolesActuales(): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasColumn('roles', 'name')) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->orderBy('roles.id')
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->mapWithKeys(fn ($f) => [$f->model_id => $f->name])
            ->all();
    }
};

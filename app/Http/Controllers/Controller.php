<?php

namespace App\Http\Controllers;

use Illuminate\Auth\AuthenticationException;

abstract class Controller
{
    /**
     * Propiedad de los datos (M1, fase de seguridad):
     * - DIRECTOR: null, sin restricción.
     * - Cualquier otro rol: id de su cuenta; solo ve y modifica sus registros.
     */
    protected function propietarioId(): ?int
    {
        $user = auth('api')->user();

        if (! $user) {
            throw new AuthenticationException();
        }

        return $user->esDirector() ? null : $user->id;
    }
}

<?php

namespace Tests\Feature\M2;

/**
 * Utilidades de las pruebas de M2 (además de Tests\Feature\M1\DatosM1).
 */
trait DatosM2
{
    // Petición sin token: se descartan la cabecera y la sesión de la petición anterior
    protected function sinSesion()
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $this->withHeaders(['Accept' => 'application/json']);
    }
}

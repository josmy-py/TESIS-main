<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // M6.1: ninguna prueba escribe fotos o documentos en el disco privado
        // real ni entradas en la bitácora real de matrículas
        Storage::fake('matriculas');
        config(['logging.channels.matriculas.path' => storage_path('framework/testing/logs/matriculas.log')]);
    }
}

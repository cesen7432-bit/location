<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SQLite no tiene FIELD() de MySQL (se usa para ordenar los días de la semana)
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction(
                'FIELD',
                fn ($value, ...$list) => ($i = array_search($value, $list)) === false ? 0 : $i + 1
            );
        }
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->abortUnlessTestDatabase();
        $this->ensurePersonalAccessClient();
    }

    /** The suite writes data (inside transactions), so it must never touch a real database. */
    protected function abortUnlessTestDatabase(): void
    {
        $name = DB::connection()->getDatabaseName();

        if (! str_ends_with($name, '_test')) {
            throw new RuntimeException("Refusing to run the tests against \"$name\": the database name must end in \"_test\".");
        }
    }

    /** Logging in issues a Passport personal access token, which needs a personal access client. */
    protected function ensurePersonalAccessClient(): void
    {
        if (! DB::table('oauth_clients')->where('personal_access_client', true)->exists()) {
            Artisan::call('passport:client', ['--personal' => true, '--name' => 'tests', '--no-interaction' => true]);
        }
    }
}

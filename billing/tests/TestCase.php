<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Load only these modules (the MODULES env value, e.g. "Core" or "Services") for the tests of one file, to prove
     * behaviour without sibling modules. Set it in beforeAll() and reset it in afterAll(); null loads every module.
     * Files that set it live in tests/Extensions, which does not refresh the database: a module subset must never
     * migrate a database the other tests share.
     */
    public static ?string $onlyModules = null;

    public function createApplication()
    {
        if (self::$onlyModules === null) {
            return parent::createApplication();
        }

        $previous = [$_ENV['MODULES'] ?? null, $_SERVER['MODULES'] ?? null];
        $_ENV['MODULES'] = $_SERVER['MODULES'] = self::$onlyModules;

        try {
            return parent::createApplication();
        } finally {
            [$env, $server] = $previous;

            if ($env === null) {
                unset($_ENV['MODULES']);
            } else {
                $_ENV['MODULES'] = $env;
            }

            if ($server === null) {
                unset($_SERVER['MODULES']);
            } else {
                $_SERVER['MODULES'] = $server;
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Page components are asserted via inertia.testing.ensure_pages_exist; no built assets needed.
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

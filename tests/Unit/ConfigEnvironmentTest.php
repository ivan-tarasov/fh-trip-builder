<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use Exception;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;

/**
 * Where the config environment comes from, and what happens when it is absent.
 *
 * Both of these were live bugs on 2026-09-13. APP_ENV reached CI as a real
 * environment variable, `Config` read `$_ENV` alone and found nothing, and the
 * path it built was `config/` itself -- a directory that exists and holds no
 * files, so the config loaded as empty rather than failing. `currency:rates`
 * was the first thing to say so, after reporting that the site has no
 * currencies configured.
 */
final class ConfigEnvironmentTest extends TestCase
{
    private string|false $exported = false;
    private ?string $loaded = null;

    protected function setUp(): void
    {
        $this->exported = getenv('APP_ENV');
        $this->loaded = isset($_ENV['APP_ENV']) ? (string) $_ENV['APP_ENV'] : null;
    }

    protected function tearDown(): void
    {
        putenv($this->exported === false ? 'APP_ENV' : 'APP_ENV=' . $this->exported);

        if ($this->loaded === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->loaded;
        }

        // The constructor empties the static store before it reads anything,
        // so the refusal below would otherwise leave every later test in this
        // process without config.
        new Config('common');
    }

    public function testItRefusesToLoadWithNoEnvironmentNamed(): void
    {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('APP_ENV is not set');

        new Config();
    }

    public function testItReadsAnEnvironmentThatOnlyTheProcessHolds(): void
    {
        putenv('APP_ENV=common');
        unset($_ENV['APP_ENV']);

        new Config();

        // Not just "an array": the failure being guarded against is a config
        // that loads and holds nothing.
        self::assertNotEmpty(Config::get('currencies'));
    }
}

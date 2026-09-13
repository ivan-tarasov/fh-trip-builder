<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Env;

/**
 * Which source wins, which is the only thing this class decides.
 */
final class EnvTest extends TestCase
{
    /** Nothing reads this; it only has to be a name no real environment holds. */
    private const string KEY = 'TRIPBUILDER_ENV_TEST';

    protected function tearDown(): void
    {
        putenv(self::KEY);
        unset($_ENV[self::KEY]);
    }

    public function testAKeyNobodySetReadsAsAnEmptyString(): void
    {
        self::assertSame('', Env::get(self::KEY));
    }

    public function testItFallsBackToWhatDotenvLoaded(): void
    {
        $_ENV[self::KEY] = 'from-dotenv';

        self::assertSame('from-dotenv', Env::get(self::KEY));
    }

    /**
     * The whole reason this class exists.
     *
     * A host that exports the variable means it, and phpdotenv's immutable
     * loader leaves `$_ENV` without it -- so reading `$_ENV` alone answers ''
     * on precisely the hosts that set it.
     */
    public function testTheRealEnvironmentBeatsWhatDotenvLoaded(): void
    {
        $_ENV[self::KEY] = 'from-dotenv';
        putenv(self::KEY . '=from-the-process');

        self::assertSame('from-the-process', Env::get(self::KEY));
    }

    /**
     * An exported empty string is an answer and not an absence: it is how
     * somebody switches a setting off for one run without editing `.env`.
     */
    public function testAnExportedEmptyStringWinsToo(): void
    {
        $_ENV[self::KEY] = 'from-dotenv';
        putenv(self::KEY . '=');

        self::assertSame('', Env::get(self::KEY));
    }
}

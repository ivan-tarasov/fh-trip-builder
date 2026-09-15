<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Env;
use TripBuilder\EnvKey;

/**
 * Which source wins, which is the only thing this class decides.
 */
final class EnvTest extends TestCase
{
    /**
     * A real case, because `Env::get()` no longer accepts an invented name
     * (E10.4, #196). This one, because nothing else in the suite reads it --
     * the API's token check is the only caller and no test goes through it.
     */
    private const EnvKey KEY = EnvKey::ApiAcceptedTokens;

    private string|false $exported = false;
    private ?string $loaded = null;

    protected function setUp(): void
    {
        $this->exported = getenv(self::KEY->value);

        /** @var string|null $loaded */
        $loaded = $_ENV[self::KEY->value] ?? null;
        $this->loaded = $loaded;

        putenv(self::KEY->value);
        unset($_ENV[self::KEY->value]);
    }

    protected function tearDown(): void
    {
        // No `=` removes the variable, which is the only way back to unset.
        putenv($this->exported === false ? self::KEY->value : self::KEY->value . '=' . $this->exported);

        if ($this->loaded === null) {
            unset($_ENV[self::KEY->value]);
        } else {
            $_ENV[self::KEY->value] = $this->loaded;
        }
    }

    public function testAKeyNobodySetReadsAsAnEmptyString(): void
    {
        self::assertSame('', Env::get(self::KEY));
    }

    public function testItFallsBackToWhatDotenvLoaded(): void
    {
        $_ENV[self::KEY->value] = 'from-dotenv';

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
        $_ENV[self::KEY->value] = 'from-dotenv';
        putenv(self::KEY->value . '=from-the-process');

        self::assertSame('from-the-process', Env::get(self::KEY));
    }

    /**
     * An exported empty string is an answer and not an absence: it is how
     * somebody switches a setting off for one run without editing `.env`.
     */
    public function testAnExportedEmptyStringWinsToo(): void
    {
        $_ENV[self::KEY->value] = 'from-dotenv';
        putenv(self::KEY->value . '=');

        self::assertSame('', Env::get(self::KEY));
    }
}

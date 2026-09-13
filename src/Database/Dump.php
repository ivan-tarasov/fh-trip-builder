<?php

declare(strict_types=1);

namespace TripBuilder\Database;

use RuntimeException;

/**
 * A `mysqldump` invocation, assembled away from the shell.
 *
 * Separated from the command so the part worth checking can be checked: the
 * dump itself is a subprocess and the only thing a test can meaningfully assert
 * about it is what it was told to do.
 *
 * The credential handling is why this is careful. `mysqldump -p<password>` puts
 * the password in the process list, where on a shared host every other account
 * on the machine can read it out of `ps`. This deployment is on exactly that
 * kind of host. So the credentials go in a file that only the owner can read,
 * and the file is removed whether or not the dump worked.
 */
final readonly class Dump
{
    /**
     * `--single-transaction` for a consistent InnoDB snapshot without locking
     * anybody out. `--quick` streams rather than buffering a whole table.
     *
     * `--no-tablespaces` is the one that is not obvious: MySQL 8 requires the
     * `PROCESS` privilege to dump tablespace information, a hosting account
     * does not have it, and without this flag the dump fails on the server it
     * is needed on and succeeds on the laptop it was written on.
     */
    private const array OPTIONS = [
        '--single-transaction',
        '--quick',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
    ];

    /**
     * `$columnStatistics` says whether this client understands
     * `--column-statistics`; see supportsColumnStatistics(). Defaulted off
     * because omitting the option is the shape that every client accepts.
     */
    public function __construct(
        private string $binary,
        private string $database,
        private bool $columnStatistics = false,
    ) {}

    /**
     * The arguments, with no credential among them.
     *
     * @return list<string>
     */
    public function arguments(string $defaultsFile): array
    {
        return [
            $this->binary,
            // First, and mysqldump insists on it: any other option before this
            // one and it refuses to start.
            '--defaults-extra-file=' . $defaultsFile,
            ...self::OPTIONS,
            ...($this->columnStatistics ? ['--column-statistics=0'] : []),
            $this->database,
        ];
    }

    /**
     * What goes in the file the arguments point at.
     *
     * `password` is quoted because a `#` in one starts a comment in this format
     * and the rest of the password is silently discarded -- which fails as an
     * access-denied error that has nothing to do with the real cause.
     */
    public static function defaults(string $host, string $port, string $user, string $password): string
    {
        return sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n",
            $host,
            $port,
            $user,
            str_replace('"', '\"', $password),
        );
    }

    /**
     * Write the credentials somewhere only this user can read them.
     *
     * Created empty and chmodded before anything is written to it, so there is
     * no instant where the file exists, readable, with a password in it.
     */
    public static function writeDefaults(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tb-dump-');

        if ($path === false || !chmod($path, 0600) || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not write the credentials file for mysqldump.');
        }

        return $path;
    }

    /**
     * Whether this `mysqldump` knows `--column-statistics`, which decides
     * whether the option can be passed at all.
     *
     * A MySQL 8 client asks `information_schema.COLUMN_STATISTICS` for a
     * histogram before it dumps each table. MariaDB has no such table, so the
     * dump dies on the first one with `Unknown table 'COLUMN_STATISTICS'` --
     * which is what CI's MariaDB job did on 2026-09-13, having never run
     * `db:backup` before (E10.1, #148). `--column-statistics=0` turns the
     * question off, and MariaDB's own client refuses the option because it
     * never asked.
     *
     * So the binary is asked what it supports rather than guessed at from the
     * server: a host can pair either client with either server, and the pairing
     * is the whole problem.
     */
    public static function supportsColumnStatistics(string $binary): bool
    {
        $help = shell_exec(sprintf('%s --help 2>/dev/null', escapeshellarg($binary)));

        return is_string($help) && str_contains($help, '--column-statistics');
    }

    public static function isAvailable(string $binary): bool
    {
        $found = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($binary)));

        return is_string($found) && trim($found) !== '';
    }

    /** `<database>-<timestamp>.sql.gz`, sorting chronologically by name. */
    public function fileName(string $stamp): string
    {
        return sprintf('%s-%s.sql.gz', preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->database), $stamp);
    }
}

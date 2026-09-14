<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Admin;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use TripBuilder\Admin;
use TripBuilder\EnvKey;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: 'admin:password',
    description: 'Hash a password for the admin panel, to be pasted into `.env`.',
    aliases: [],
    hidden: false,
)]

/**
 * Turn a password into the line that goes in `.env`.
 *
 * The panel checks `password_verify()` against a hash and there is nowhere to
 * type a new password inside the app, so this is the only way one is ever set
 * (A3.2, #100). One operator, one hash, no accounts table.
 *
 * **Asked for, never passed as an argument.** A password on a command line is
 * in the shell history, in `ps` output, and in whatever ships that history off
 * the box -- so it is prompted for, with the echo off, and typed twice.
 *
 * It never touches the database and never writes `.env` itself. Printing the
 * line and letting a person paste it means this command is safe to run on any
 * machine, including one that is not the server.
 */
class Password extends AbstractCommand
{
    /**
     * Below this, a hash is the only slow part of guessing.
     *
     * The panel's throttle is ten tries an hour from one address, which is the
     * real defence; this is what is left if the hash itself is ever taken.
     */
    private const int MINIMUM_LENGTH = 12;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        // Narrowed for the analyser: getHelper() is typed to the interface
        // every helper shares, and only this one can ask a question.
        if (!$helper instanceof QuestionHelper) {
            $this->io->error('The console has no way to ask a question.');

            return Command::FAILURE;
        }

        if (!$input->isInteractive()) {
            // Non-interactive means a pipe or a cron, and the only way to
            // supply a password to either is to write it down somewhere.
            $this->io->error('This asks for a password, so it has to be run by hand.');

            return Command::FAILURE;
        }

        $password = (string) $helper->ask($input, $output, self::hidden('Password: '));

        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            $this->io->error(sprintf('At least %d characters, please.', self::MINIMUM_LENGTH));

            return Command::FAILURE;
        }

        // Typed twice because it is never shown and cannot be recovered: the
        // failure this catches is a typo that locks the owner out of their own
        // panel until they notice and run this again.
        if ($password !== (string) $helper->ask($input, $output, self::hidden('Again: '))) {
            $this->io->error('Those did not match.');

            return Command::FAILURE;
        }

        // PASSWORD_DEFAULT, not a named algorithm: it is what PHP considers
        // best today, and `password_verify()` reads the algorithm back out of
        // the hash -- so a hash made now still verifies after the default moves.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->io->success('Put this line in `.env`:');
        $this->io->writeln(EnvKey::AdminPasswordHash->value . '=' . $hash);
        $this->io->newLine();
        $this->io->note(sprintf(
            'Nothing was written. The panel reads this at sign-in, and a session there'
            . ' ends after %d minutes idle.',
            Admin::IDLE_MINUTES,
        ));

        return Command::SUCCESS;
    }

    /** A question whose answer is not echoed and not kept in the history. */
    private static function hidden(string $prompt): Question
    {
        $question = new Question($prompt);
        $question->setHidden(true);
        // Refuse rather than fall back to visible typing: a terminal that
        // cannot hide input would otherwise print the password on screen.
        $question->setHiddenFallback(false);

        return $question;
    }
}

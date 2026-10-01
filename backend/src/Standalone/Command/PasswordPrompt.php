<?php

declare(strict_types=1);

namespace App\Standalone\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** A new password, asked twice at a terminal or read as one line from a pipe. */
final class PasswordPrompt
{
    public static function read(InputInterface $input, SymfonyStyle $io): ?string
    {
        if (!$input->isInteractive()) {
            $stream = $input instanceof StreamableInputInterface && $input->getStream() !== null ? $input->getStream() : STDIN;
            $line = fgets($stream);

            return $line === false ? null : rtrim($line, "\r\n");
        }

        $password = (string) $io->askHidden('New password');
        if ($password !== (string) $io->askHidden('The same again')) {
            $io->error('The two did not match.');

            return null;
        }

        return $password;
    }
}

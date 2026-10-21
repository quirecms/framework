<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fixture:hello', description: 'Says hello from the fixture extension')]
final readonly class HelloCommand
{
    public function __construct(
        private Greeter $greeter,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln($this->greeter->message('the console'));

        return Command::SUCCESS;
    }
}

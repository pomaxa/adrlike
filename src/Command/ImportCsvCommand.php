<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CsvImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-csv',
    description: 'Import decisions from a Product_changes-style CSV file.',
)]
final class ImportCsvCommand extends Command
{
    public function __construct(private readonly CsvImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file')
            ->addOption('encoding', null, InputOption::VALUE_REQUIRED, 'Source encoding (default: auto-detect)', CsvImporter::ENCODING_AUTO)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse but do not persist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('file');
        if (!is_file($path) || !is_readable($path)) {
            $io->error("Cannot read file: {$path}");

            return Command::FAILURE;
        }

        $result = $this->importer->import(
            (string) file_get_contents($path),
            (string) $input->getOption('encoding'),
            (bool) $input->getOption('dry-run'),
        );

        $io->text(sprintf('Source encoding: <info>%s</info>', $result->sourceEncoding));

        foreach ($result->warnings as $w) {
            $io->warning($w);
        }

        if (!$result->isOk()) {
            $io->error($result->fatalError);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported %d decisions, skipped %d duplicates, created %d users.',
            $result->created, $result->skipped, $result->newUsers
        ));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Command;

use Imhlab\TtnewsLinkConverter\Domain\Dto\ConversionResult;
use Imhlab\TtnewsLinkConverter\Service\LinkConversionLogWriter;
use Imhlab\TtnewsLinkConverter\Service\LinkConverterService;
use Imhlab\TtnewsLinkConverter\Service\RecordFieldWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConvertLinksCommand extends Command
{
    public function __construct(
        private readonly LinkConverterService $converterService,
        private readonly RecordFieldWriter $writer,
        private readonly LinkConversionLogWriter $logWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Convert legacy tt_news RTE link tags in scanned tables/fields to TYPO3 record-link syntax')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Write changes to the database (default: dry-run only)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max candidate rows to process (0 = unlimited)', '0')
            ->addOption('log-file', null, InputOption::VALUE_REQUIRED, 'Override the default issue-log path')
            ->addOption('log-format', null, InputOption::VALUE_REQUIRED, 'Log format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $logFormat = (string)$input->getOption('log-format');
        if (!in_array($logFormat, ['text', 'json'], true)) {
            $io->error(sprintf('Invalid --log-format "%s" - expected "text" or "json".', $logFormat));

            return Command::FAILURE;
        }

        $logFileOption = $input->getOption('log-file');
        $logFile = is_string($logFileOption) && $logFileOption !== ''
            ? $logFileOption
            : $this->logWriter->getDefaultPath();

        $execute = (bool)$input->getOption('execute');
        $limit = (int)$input->getOption('limit');

        $results = $this->converterService->convertAll($limit);

        $changed = array_values(array_filter(
            $results,
            static fn (ConversionResult $result): bool => $result->hasChanges()
        ));

        $issues = array_merge([], ...array_map(
            static fn (ConversionResult $result): array => $result->issues,
            $results
        ));

        $convertedLinkCount = array_sum(array_map(
            static fn (ConversionResult $result): int => count($result->converted),
            $results
        ));

        $io->table(
            ['Metric', 'Count'],
            [
                ['Candidate rows scanned', (string)count($results)],
                ['Rows/fields with changes', (string)count($changed)],
                ['Links converted', (string)$convertedLinkCount],
                ['Issues (unresolved / unhandled)', (string)count($issues)],
            ]
        );

        if ($execute && $changed !== []) {
            $writePayload = [];
            foreach ($changed as $result) {
                $writePayload[$result->table][$result->sourceUid][$result->field] = $result->newValue;
            }

            $writeResult = $this->writer->updateFields($writePayload);

            if ($writeResult->errors !== []) {
                foreach ($writeResult->errors as $error) {
                    $io->error($error);
                }

                return Command::FAILURE;
            }
        }

        $this->logWriter->write($logFile, $logFormat, $issues);

        if ($execute) {
            $io->success(sprintf(
                'Executed: updated %d row/field combination(s), %d link(s) converted. %d issue(s) logged to %s.',
                count($changed),
                $convertedLinkCount,
                count($issues),
                $logFile
            ));
        } else {
            $io->note('Dry-run only - no changes were written. Pass --execute to write.');
            $io->success(sprintf(
                'Dry-run: %d row/field combination(s) would change, %d link(s) would convert. %d issue(s) logged to %s.',
                count($changed),
                $convertedLinkCount,
                count($issues),
                $logFile
            ));
        }

        return Command::SUCCESS;
    }
}

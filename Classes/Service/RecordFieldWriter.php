<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Service;

use Imhlab\TtnewsLinkConverter\Domain\Dto\BatchWriteResult;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Isolated DataHandler write boundary, table-agnostic (any table registered as a scan
 * target in LinkConverterService can be written back here). Kept separate from
 * LinkConverterService so the scan/transform logic stays free of DataHandler/CLI-bootstrap
 * concerns.
 */
final class RecordFieldWriter
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * @param array<string, array<int, array<string, string>>> $tableUidFieldValues
     *   table => uid => field => new value
     */
    public function updateFields(array $tableUidFieldValues): BatchWriteResult
    {
        if ($tableUidFieldValues === []) {
            return new BatchWriteResult([], []);
        }

        $this->bootstrapCliRequestContext();

        $data = [];
        $updatedRefs = [];
        foreach ($tableUidFieldValues as $table => $uidFieldValues) {
            foreach ($uidFieldValues as $uid => $fieldValues) {
                $data[$table][$uid] = $fieldValues;
                $updatedRefs[] = sprintf('%s:%d', $table, $uid);
            }
        }

        // One batched process_datamap() call for every changed row across every table,
        // not one per row - avoids re-running the bootstrap/reference-index maintenance
        // per row for what can be well over a thousand rows in a single run.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        return new BatchWriteResult($updatedRefs, $dataHandler->errorLog);
    }

    /**
     * DataHandler triggers hooks that build a PSR-7 ServerRequest from $_SERVER, which
     * isn't populated on CLI. Resolve the host dynamically from the first configured site
     * rather than hardcoding one, so this command works unmodified on any environment.
     */
    private function bootstrapCliRequestContext(): void
    {
        $site = current($this->siteFinder->getAllSites());

        if ($site === false) {
            throw new \RuntimeException(
                'No TYPO3 site configuration found - cannot bootstrap a request context for DataHandler on CLI.',
                1758000001
            );
        }

        $base = $site->getBase();
        $_SERVER['HTTP_HOST'] = $base->getHost();
        $_SERVER['HTTPS'] = $base->getScheme() === 'https' ? 'on' : 'off';
        $_SERVER['REQUEST_URI'] = '/';

        Bootstrap::initializeBackendAuthentication();
    }
}

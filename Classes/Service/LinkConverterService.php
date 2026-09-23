<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Service;

use Imhlab\TtnewsLinkConverter\Domain\Dto\ConversionResult;
use Imhlab\TtnewsLinkConverter\Domain\Dto\ConvertedLink;
use Imhlab\TtnewsLinkConverter\Domain\Dto\LinkIssue;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Orchestrates scanning + transforming candidate rows. Never writes to the database -
 * safe to call unconditionally in both dry-run and --execute mode; the command decides
 * whether to persist the results.
 */
final class LinkConverterService
{
    private const CANDIDATE_TABLE = 'tx_news_domain_model_news';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LegacyLinkParser $parser,
        private readonly NewsImportIdResolver $resolver,
    ) {
    }

    /**
     * @return list<ConversionResult>
     */
    public function convertAll(int $limit = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CANDIDATE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();

        $queryBuilder
            ->select('uid', 'bodytext')
            ->from(self::CANDIDATE_TABLE)
            ->where(
                $expr->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $expr->like('bodytext', $queryBuilder->createNamedParameter('%record:tt_news:%')),
            )
            ->orderBy('uid');

        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        $results = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $results[] = $this->convertRow((int)$row['uid'], (string)$row['bodytext']);
        }

        return $results;
    }

    public function convertRow(int $sourceUid, string $bodytext): ConversionResult
    {
        $converted = [];
        $issues = [];

        $resolve = function (int $oldUid, string $linkText, string $patternType) use ($sourceUid, &$converted, &$issues): ?string {
            $newUid = $this->resolver->resolve($oldUid);

            if ($newUid === null) {
                $issues[] = new LinkIssue(
                    $sourceUid,
                    $oldUid,
                    LinkIssue::TYPE_UNRESOLVED_IMPORT_ID,
                    sprintf('record:tt_news:%d', $oldUid),
                    sprintf('No tx_news row with import_source=TT_NEWS_IMPORT and import_id=%d.', $oldUid),
                );

                return null;
            }

            $converted[] = new ConvertedLink($sourceUid, $oldUid, $newUid, $linkText, $patternType);

            return sprintf('<a href="t3://record?identifier=tx_news&uid=%d">%s</a>', $newUid, $linkText);
        };

        $onUnhandled = function (string $rawSnippet, string $filmId) use ($sourceUid, &$issues): void {
            $issues[] = new LinkIssue(
                $sourceUid,
                null,
                LinkIssue::TYPE_UNHANDLED_FILMID_REDIRECT,
                mb_substr($rawSnippet, 0, 200),
                sprintf('Legacy filmid redirect link (filmid=%s) is out of scope - not converted.', $filmId),
            );
        };

        $newBodytext = $this->parser->convert($bodytext, $resolve, $onUnhandled);

        return new ConversionResult($sourceUid, $bodytext, $newBodytext, $converted, $issues);
    }
}

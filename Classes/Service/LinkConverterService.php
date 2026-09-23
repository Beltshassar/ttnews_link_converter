<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Service;

use Imhlab\TtnewsLinkConverter\Domain\Dto\ConversionResult;
use Imhlab\TtnewsLinkConverter\Domain\Dto\ConvertedLink;
use Imhlab\TtnewsLinkConverter\Domain\Dto\LinkIssue;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Orchestrates scanning + transforming candidate rows across every configured
 * table/field target. Never writes to the database - safe to call unconditionally
 * in both dry-run and --execute mode; the command decides whether to persist results.
 */
final class LinkConverterService
{
    /**
     * Tables/fields to scan for legacy tt_news link markup. Add an entry here for any
     * other table that may carry imported content with these link tags.
     *
     * @var list<array{table: string, fields: list<string>}>
     */
    private const SCAN_TARGETS = [
        ['table' => 'tx_news_domain_model_news', 'fields' => ['bodytext']],
        ['table' => 'tt_content', 'fields' => ['bodytext', 'teaser']],
    ];

    /**
     * A row is only worth fetching if it contains something one of the parser patterns
     * might match - covers both the "record:tt_news:" tags/anchors and the filmid-redirect
     * links, which don't necessarily co-occur with a "record:tt_news:" reference.
     */
    private const CANDIDATE_MARKERS = [
        '%record:tt_news:%',
        '%filmogtro.dk/index.php?site=anmeldelserread%',
    ];

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
        $results = [];

        foreach (self::SCAN_TARGETS as $target) {
            foreach ($target['fields'] as $field) {
                foreach ($this->fetchCandidateRows($target['table'], $field, $limit) as $uid => $value) {
                    $results[] = $this->convertField($target['table'], $uid, $field, $value);

                    if ($limit > 0 && count($results) >= $limit) {
                        return $results;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @return array<int, string> uid => field value
     */
    private function fetchCandidateRows(string $table, string $field, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();

        $markerConditions = array_map(
            static fn (string $marker) => $expr->like($field, $queryBuilder->createNamedParameter($marker)),
            self::CANDIDATE_MARKERS
        );

        $queryBuilder
            ->select('uid', $field)
            ->from($table)
            ->where(
                $expr->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $expr->or(...$markerConditions),
            )
            ->orderBy('uid');

        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        $rows = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $rows[(int)$row['uid']] = (string)$row[$field];
        }

        return $rows;
    }

    public function convertField(string $table, int $sourceUid, string $field, string $value): ConversionResult
    {
        $converted = [];
        $issues = [];

        $resolve = function (int $oldUid, string $linkText, string $patternType) use ($table, $sourceUid, $field, &$converted, &$issues): ?string {
            $newUid = $this->resolver->resolve($oldUid);

            if ($newUid === null) {
                $issues[] = new LinkIssue(
                    $table,
                    $sourceUid,
                    $field,
                    $oldUid,
                    LinkIssue::TYPE_UNRESOLVED_IMPORT_ID,
                    sprintf('record:tt_news:%d', $oldUid),
                    sprintf('No tx_news row with import_source=TT_NEWS_IMPORT and import_id=%d.', $oldUid),
                );

                return null;
            }

            $converted[] = new ConvertedLink($table, $sourceUid, $field, $oldUid, $newUid, $linkText, $patternType);

            return sprintf('<a href="t3://record?identifier=tx_news&uid=%d">%s</a>', $newUid, $linkText);
        };

        $onUnhandled = function (string $rawSnippet, string $filmId) use ($table, $sourceUid, $field, &$issues): void {
            $issues[] = new LinkIssue(
                $table,
                $sourceUid,
                $field,
                null,
                LinkIssue::TYPE_UNHANDLED_FILMID_REDIRECT,
                mb_substr($rawSnippet, 0, 200),
                sprintf('Legacy filmid redirect link (filmid=%s) is out of scope - not converted.', $filmId),
            );
        };

        $newValue = $this->parser->convert($value, $resolve, $onUnhandled);

        return new ConversionResult($table, $sourceUid, $field, $value, $newValue, $converted, $issues);
    }
}

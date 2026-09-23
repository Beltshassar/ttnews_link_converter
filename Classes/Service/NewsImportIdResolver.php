<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Resolves an old tt_news uid (as embedded in legacy link tags) to the current
 * tx_news_domain_model_news uid, via the import_id carried over by the migration.
 * The two uid spaces are unrelated - a uid match would be coincidental, not correct.
 */
final class NewsImportIdResolver
{
    private const TABLE = 'tx_news_domain_model_news';
    private const IMPORT_SOURCE = 'TT_NEWS_IMPORT';

    /** @var array<int, int|null> */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function resolve(int $oldUid): ?int
    {
        if (array_key_exists($oldUid, $this->cache)) {
            return $this->cache[$oldUid];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();

        $uids = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $expr->eq('import_source', $queryBuilder->createNamedParameter(self::IMPORT_SOURCE)),
                $expr->eq('import_id', $queryBuilder->createNamedParameter((string)$oldUid)),
                $expr->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        // More than one match is treated the same as "no match" (logged by the caller,
        // never guessed) - import_id is expected unique per TT_NEWS_IMPORT row.
        return $this->cache[$oldUid] = count($uids) === 1 ? (int)$uids[0] : null;
    }
}

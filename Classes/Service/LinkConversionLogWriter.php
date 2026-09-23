<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Service;

use Imhlab\TtnewsLinkConverter\Domain\Dto\LinkIssue;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes the per-run issue log (unresolved import_id lookups, unhandled filmid-redirect
 * links). Overwritten each run - the log reflects the current remaining problem set,
 * not an accumulation across repeated runs.
 */
final class LinkConversionLogWriter
{
    public function getDefaultPath(): string
    {
        return Environment::getVarPath() . '/log/ttnews_link_converter-unresolved_links.log';
    }

    /**
     * @param list<LinkIssue> $issues
     */
    public function write(string $path, string $format, array $issues): void
    {
        GeneralUtility::mkdir_deep(dirname($path));

        $content = $format === 'json' ? $this->renderJson($issues) : $this->renderText($issues);

        GeneralUtility::writeFile($path, $content);
    }

    /**
     * @param list<LinkIssue> $issues
     */
    private function renderText(array $issues): string
    {
        $generatedAt = (new \DateTimeImmutable())->format(DATE_ATOM);

        if ($issues === []) {
            return sprintf("%s | no unresolved or unhandled links found\n", $generatedAt);
        }

        $lines = [];
        foreach ($issues as $issue) {
            $lines[] = sprintf(
                "%s | source_uid=%d | old_uid=%s | type=%s\n  | message=\"%s\"\n  | snippet=%s",
                $generatedAt,
                $issue->sourceUid,
                $issue->oldUid === null ? 'n/a' : (string)$issue->oldUid,
                $issue->type,
                $issue->message,
                $issue->rawSnippet,
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<LinkIssue> $issues
     */
    private function renderJson(array $issues): string
    {
        $payload = [
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'issueCount' => count($issues),
            'issues' => array_map(
                static fn (LinkIssue $issue): array => [
                    'sourceUid' => $issue->sourceUid,
                    'oldUid' => $issue->oldUid,
                    'type' => $issue->type,
                    'message' => $issue->message,
                    'snippet' => $issue->rawSnippet,
                ],
                $issues
            ),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

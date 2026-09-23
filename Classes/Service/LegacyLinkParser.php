<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Service;

/**
 * Stateless regex engine for legacy tt_news RTE link markup. Knows nothing about the
 * database or TYPO3 - the caller supplies resolution behaviour via callbacks, so this
 * class stays trivially unit-testable.
 */
final class LegacyLinkParser
{
    private const LINK_TAG_PATTERN =
        '#<link\s+record:tt_news:(?P<olduid>\d+)\b[^>]*>(?P<text>.*?)</link>#is';

    private const RAW_ANCHOR_PATTERN =
        '#<a\s+href="record:tt_news:(?P<olduid>\d+)"[^>]*>(?P<text>.*?)</a>#is';

    private const FILMID_REDIRECT_PATTERN =
        '#<link\s+http://filmogtro\.dk/index\.php\?site=anmeldelserread&link=stlink&filmid=(?P<filmid>\d+)[^>]*>(?P<text>.*?)</link>#is';

    /**
     * @param callable(int $oldUid, string $linkText, string $patternType): ?string $resolve
     *   Return replacement HTML for a matched tag, or null to leave the original tag
     *   untouched (e.g. no import_id match was found).
     * @param callable(string $rawSnippet, string $filmId): void $onUnhandled
     *   Called for legacy filmid-redirect links, which are detected but never converted.
     */
    public function convert(string $bodytext, callable $resolve, callable $onUnhandled): string
    {
        $bodytext = preg_replace_callback(
            self::LINK_TAG_PATTERN,
            static fn (array $matches): string => $resolve((int)$matches['olduid'], $matches['text'], 'link_tag') ?? $matches[0],
            $bodytext
        ) ?? $bodytext;

        $bodytext = preg_replace_callback(
            self::RAW_ANCHOR_PATTERN,
            static fn (array $matches): string => $resolve((int)$matches['olduid'], $matches['text'], 'raw_anchor') ?? $matches[0],
            $bodytext
        ) ?? $bodytext;

        preg_replace_callback(
            self::FILMID_REDIRECT_PATTERN,
            static function (array $matches) use ($onUnhandled): string {
                $onUnhandled($matches[0], $matches['filmid']);

                return $matches[0];
            },
            $bodytext
        );

        return $bodytext;
    }
}

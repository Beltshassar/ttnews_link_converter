<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Domain\Dto;

final class ConvertedLink
{
    public const PATTERN_LINK_TAG = 'link_tag';
    public const PATTERN_RAW_ANCHOR = 'raw_anchor';

    public function __construct(
        public readonly int $sourceUid,
        public readonly int $oldUid,
        public readonly int $newUid,
        public readonly string $linkText,
        public readonly string $patternType,
    ) {
    }
}

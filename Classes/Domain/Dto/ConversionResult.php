<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Domain\Dto;

final class ConversionResult
{
    /**
     * @param list<ConvertedLink> $converted
     * @param list<LinkIssue> $issues
     */
    public function __construct(
        public readonly int $sourceUid,
        public readonly string $originalBodytext,
        public readonly string $newBodytext,
        public readonly array $converted,
        public readonly array $issues,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->newBodytext !== $this->originalBodytext;
    }
}

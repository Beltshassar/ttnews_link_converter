<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Domain\Dto;

final class LinkIssue
{
    public const TYPE_UNRESOLVED_IMPORT_ID = 'unresolved_import_id';
    public const TYPE_UNHANDLED_FILMID_REDIRECT = 'unhandled_filmid_redirect';

    public function __construct(
        public readonly string $table,
        public readonly int $sourceUid,
        public readonly string $field,
        public readonly ?int $oldUid,
        public readonly string $type,
        public readonly string $rawSnippet,
        public readonly string $message,
    ) {
    }
}

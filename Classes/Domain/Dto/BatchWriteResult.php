<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Domain\Dto;

final class BatchWriteResult
{
    /**
     * @param list<string> $updatedRefs "table:uid" references that were sent to DataHandler
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $updatedRefs,
        public readonly array $errors,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Imhlab\TtnewsLinkConverter\Domain\Dto;

final class BatchWriteResult
{
    /**
     * @param list<int> $updatedUids
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $updatedUids,
        public readonly array $errors,
    ) {
    }
}

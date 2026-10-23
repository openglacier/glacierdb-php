<?php

declare(strict_types=1);

namespace GlacierDB;

final class Result
{
    /**
     * @param list<mixed> $documents
     * @param array<string,mixed>|null $statistics
     */
    public function __construct(
        public readonly array $documents = [],
        public readonly ?array $statistics = null,
    ) {}
}

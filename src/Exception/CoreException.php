<?php

declare(strict_types=1);

namespace GlacierDB\Exception;

final class CoreException extends GlacierDBException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $coreCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct("{$coreCode}: {$message}");
    }
}

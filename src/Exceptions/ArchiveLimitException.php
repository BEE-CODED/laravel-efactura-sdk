<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

use BeeCoded\EFacturaSdk\Enums\ArchiveLimitReason;

/**
 * The archive could not be inspected within the limits, or a local failure stopped the
 * inspection. Says nothing about the document: downloading it again may pass.
 */
class ArchiveLimitException extends ArchiveException
{
    public function __construct(public readonly ArchiveLimitReason $reason, string $message)
    {
        parent::__construct($message);
    }
}

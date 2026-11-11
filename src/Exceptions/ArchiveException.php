<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

/**
 * A response archive could not be verified. Catch a subclass to tell a retryable
 * limit (ArchiveLimitException) from a final content mismatch (ArchiveMismatchException).
 */
class ArchiveException extends EFacturaException {}

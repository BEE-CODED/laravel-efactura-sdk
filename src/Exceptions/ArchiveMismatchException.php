<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

/**
 * The archive's content is not the expected document (or not only it). Repeating the
 * comparison cannot change that.
 */
class ArchiveMismatchException extends ArchiveException {}

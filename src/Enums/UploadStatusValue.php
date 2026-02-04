<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Enums;

/**
 * Status values for upload processing (stare field).
 * As defined in OpenAPI spec for status check responses.
 */
enum UploadStatusValue: string
{
    /** Processing completed successfully */
    case Ok = 'ok';

    /** Processing failed */
    case Failed = 'nok';

    /** Currently being processed */
    case InProgress = 'in prelucrare';
}

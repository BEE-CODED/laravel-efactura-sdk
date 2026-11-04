<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

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

    /**
     * The file was not taken in by ANAF ("factura nu a fost preluata de sistem").
     * A final answer for the upload index, without a download id.
     */
    case NotProcessed = 'XML cu erori nepreluat de sistem';
}

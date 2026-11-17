<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * What one status answer means for an uploaded document. See StatusResponseData::verdict().
 */
enum StatusVerdict: string
{
    /** ANAF is still processing the document ("in prelucrare") */
    case InProgress = 'in_progress';

    /** Accepted ("ok"), with the download id of its response archive */
    case Ready = 'ready';

    /** Rejected on its merits ("nok"), with the download id of the error archive */
    case Rejected = 'rejected';

    /**
     * "ok" without a usable download id: ANAF reports it filed, but there is no response archive
     * to download or prove it; the caller records it as accepted without an archive or parks it,
     * and never re-uploads it
     */
    case ReadyWithoutDownloadId = 'ready_without_download_id';

    /** "nok" without a usable download id: not a verdict on the document */
    case RejectedWithoutDownloadId = 'rejected_without_download_id';

    /** ANAF did not take the file in ("XML cu erori nepreluat de sistem"): final, no archive */
    case NotProcessed = 'not_processed';

    /** ANAF answered with an error instead of a status (see hasAnafError()) */
    case AnafError = 'anaf_error';

    /** No status ANAF defines and no error */
    case Unknown = 'unknown';
}

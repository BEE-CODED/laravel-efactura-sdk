<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Why an upload was refused or not sent. See UploadOutcome.
 */
enum UploadOutcomeReason: string
{
    /** Refused: ANAF answered the upload with a 4xx (other than 408 and 429) */
    case Rejected = 'rejected';

    /** Refused: ANAF answered ExecutionStatus 1 without an upload index */
    case FailedWithoutIndex = 'failed_without_index';

    /** Refused: the SDK's own validation refused the document before sending it */
    case LocalRefusal = 'local_refusal';

    /** Not sent: a transport failure that provably happened before sending */
    case Transport = 'transport';

    /** Not sent: a rate limit, the SDK's own or ANAF's 429 */
    case RateLimited = 'rate_limited';

    /** Not sent: ANAF refused the OAuth grant or the token (400/401); reconnect the company */
    case GrantRefused = 'grant_refused';

    /** Not sent: any other authentication failure (OAuth server unreachable, lock timeout, configuration) */
    case Transient = 'transient';
}

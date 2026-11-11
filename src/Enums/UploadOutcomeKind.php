<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * What an upload attempt means for the document. See UploadOutcome.
 */
enum UploadOutcomeKind: string
{
    /** ANAF took the document in and returned its upload index */
    case Accepted = 'accepted';

    /** The document was refused (by ANAF or by the SDK's own checks) and is not filed */
    case Refused = 'refused';

    /** The upload provably never reached ANAF, or was refused before ANAF acted on it */
    case NotSent = 'not_sent';

    /** ANAF may have filed the document: reconcile before sending it again */
    case Uncertain = 'uncertain';
}

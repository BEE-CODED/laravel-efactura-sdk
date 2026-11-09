<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Which SDK rate limit a RateLimitExceededException hit.
 *
 * Values match RateLimiter::getRemainingQuota()'s type names. Global and
 * CompanyLookup are single buckets that stop every call of their kind; the rest
 * are per CUI or per message, so only calls for that CUI/message are blocked.
 */
enum RateLimitScope: string
{
    /** Global per-minute limit, shared by every e-Factura call (uploads included) */
    case Global = 'global';

    /** Company lookup (PlatitorTvaRest) per-second limit, a single bucket */
    case CompanyLookup = 'company_lookup';

    /** RASP upload limit for one CUI */
    case RaspUpload = 'rasp_upload';

    /** Status query limit for one upload index: other indexes can still be polled */
    case Status = 'status';

    /** Simple message list limit for one CUI */
    case SimpleList = 'simple_list';

    /** Paginated message list limit for one CUI */
    case PaginatedList = 'paginated_list';

    /** Download limit for one download ID */
    case Download = 'download';
}

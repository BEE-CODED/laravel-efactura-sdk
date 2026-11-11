<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Why ArchiveInspector could not inspect a response archive. See ArchiveLimitException.
 */
enum ArchiveLimitReason: string
{
    /** The archive is over 20 MiB, or its members expand to over 20 MiB together */
    case ArchiveTooLarge = 'archive_too_large';

    /** A member expands to over 10 MiB */
    case MemberTooLarge = 'member_too_large';

    /** A member is encrypted */
    case Encrypted = 'encrypted';

    /** The archive has more than 32 members */
    case TooManyMembers = 'too_many_members';

    /** The archive has no members */
    case Empty = 'empty';

    /** The bytes are not a readable ZIP archive */
    case Unopenable = 'unopenable';

    /** A local failure (temporary file, memory): the same bytes may pass on another attempt */
    case Io = 'io';
}

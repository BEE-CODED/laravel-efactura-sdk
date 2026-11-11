<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

use BeeCoded\EFacturaSdk\Enums\ArchiveLimitReason;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveLimitException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveMismatchException;
use DOMDocument;
use ZipArchive;

/**
 * Checks a response archive downloaded from ANAF (descarcare).
 *
 * Every archive must be a readable ZIP of 1 to 32 unencrypted members, each at most
 * 10 MiB (UblDocumentComparator::MAX_BYTES, so every member it admits can be compared)
 * and together at most 20 MiB expanded (20 MiB compressed too). With the uploaded XML
 * it must also hold exactly that Invoice/CreditNote (compared with
 * UblDocumentComparator) and otherwise only xmldsig Signature documents. Without it
 * (limits-only, e.g. a rejection archive) no member is read.
 */
final class ArchiveInspector
{
    private const MAX_ARCHIVE_BYTES = 20 * 1024 * 1024;

    private const MAX_MEMBERS = 32;

    private const XMLDSIG = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(
        /** Where the archive is written for ZipArchive; the system temp directory when null */
        private readonly ?string $temporaryDirectory = null,
    ) {}

    /**
     * @throws ArchiveLimitException when the archive cannot be inspected within the limits
     * @throws ArchiveMismatchException when it does not hold exactly $uploadedXml (only when given),
     *                                  or $uploadedXml is too large to compare with any archive
     */
    public function verify(string $archive, ?string $uploadedXml = null): void
    {
        // Checked before the archive: its copy of such an upload would be a retryable limit forever.
        if ($uploadedXml !== null && (strlen($uploadedXml) > UblDocumentComparator::MAX_BYTES || substr_count($uploadedXml, '<') > UblDocumentComparator::MAX_MARKUP)) {
            throw new ArchiveMismatchException('The uploaded document is too large to compare.');
        }

        if (strlen($archive) > self::MAX_ARCHIVE_BYTES) {
            throw new ArchiveLimitException(ArchiveLimitReason::ArchiveTooLarge, 'ANAF archive exceeds the size limit.');
        }

        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();
        // tempnam() silently falls back to the system directory when $directory is unusable.
        $path = is_dir($directory) && is_writable($directory) ? @tempnam($directory, 'efactura-archive-') : false;
        if ($path === false) {
            throw new ArchiveLimitException(ArchiveLimitReason::Io, 'Unable to create a temporary file for the ANAF archive.');
        }

        $zip = new ZipArchive;
        $opened = false;

        try {
            if (file_put_contents($path, $archive) !== strlen($archive)) {
                throw new ArchiveLimitException(ArchiveLimitReason::Io, 'Unable to write the ANAF archive to a temporary file.');
            }

            // Read-only: an empty file is not an archive, and close() never rewrites or deletes the file.
            $result = $zip->open($path, ZipArchive::RDONLY);
            if ($result !== true) {
                $io = in_array($result, [ZipArchive::ER_OPEN, ZipArchive::ER_READ, ZipArchive::ER_TMPOPEN, ZipArchive::ER_MEMORY], true);

                throw new ArchiveLimitException($io ? ArchiveLimitReason::Io : ArchiveLimitReason::Unopenable, 'Invalid ANAF archive.');
            }
            $opened = true;

            $sizes = $this->checkLimits($zip);

            if ($uploadedXml !== null) {
                $this->checkContent($zip, $sizes, $uploadedXml);
            }
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    /**
     * Every limit is checked before any member is read, so an archive gets the same
     * limit failure with or without the uploaded XML.
     *
     * @return list<int> each member's declared size
     */
    private function checkLimits(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1) {
            throw new ArchiveLimitException(ArchiveLimitReason::Empty, 'ANAF archive has no members.');
        }
        if ($zip->numFiles > self::MAX_MEMBERS) {
            throw new ArchiveLimitException(ArchiveLimitReason::TooManyMembers, 'ANAF archive has too many members.');
        }

        $sizes = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new ArchiveLimitException(ArchiveLimitReason::Unopenable, 'Unreadable ANAF archive member.');
            }
            if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new ArchiveLimitException(ArchiveLimitReason::Encrypted, 'Encrypted ANAF archive member.');
            }
            if ($stat['size'] > UblDocumentComparator::MAX_BYTES) {
                throw new ArchiveLimitException(ArchiveLimitReason::MemberTooLarge, 'ANAF archive member exceeds the size limit.');
            }
            $sizes[] = $stat['size'];
            if (array_sum($sizes) > self::MAX_ARCHIVE_BYTES) {
                throw new ArchiveLimitException(ArchiveLimitReason::ArchiveTooLarge, 'ANAF archive expands beyond the size limit.');
            }
        }

        return $sizes;
    }

    /** @param list<int> $sizes */
    private function checkContent(ZipArchive $zip, array $sizes, string $uploadedXml): void
    {
        $comparator = new UblDocumentComparator;
        $matched = 0;

        foreach ($sizes as $index => $size) {
            // Capped read: a member that expands beyond its declared size is never held whole.
            $content = $zip->getFromIndex($index, UblDocumentComparator::MAX_BYTES + 1);
            if (! is_string($content) || $content === '' || strlen($content) !== $size || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $content) === 1) {
                throw new ArchiveMismatchException('Unverifiable ANAF archive member.');
            }
            // A small member can still parse into millions of nodes.
            if (substr_count($content, '<') > UblDocumentComparator::MAX_MARKUP) {
                throw new ArchiveLimitException(ArchiveLimitReason::MemberTooLarge, 'ANAF archive member holds too much markup.');
            }

            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $loaded = $document->loadXML($content, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $root = $loaded && $document->doctype === null ? $document->documentElement : null;
            if ($root === null) {
                throw new ArchiveMismatchException('ANAF archive contains an unsupported member.');
            }

            if (in_array($root->localName, ['Invoice', 'CreditNote'], true)) {
                $comparator->assertSameDocument($content, $uploadedXml);
                $matched++;
            } elseif ($root->localName !== 'Signature' || $root->namespaceURI !== self::XMLDSIG) {
                throw new ArchiveMismatchException('ANAF archive contains an unrelated document.');
            }
        }

        if ($matched !== 1) {
            throw new ArchiveMismatchException('ANAF archive does not hold exactly the uploaded document.');
        }
    }
}

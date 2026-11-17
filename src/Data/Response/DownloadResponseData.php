<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

use Spatie\LaravelData\Data;

/**
 * Response from document download operation.
 * Contains the binary content of the downloaded ZIP file.
 */
class DownloadResponseData extends Data
{
    public function __construct(
        /** Binary content of the ZIP file */
        public string $content,

        /** Content type (e.g., application/zip) */
        public string $contentType,

        /** Base name suggested by the Content-Disposition header (untrusted; null when absent or empty) */
        public ?string $filename = null,

        /** Content length in bytes */
        public ?int $contentLength = null,
    ) {}

    /**
     * Create from HTTP response.
     *
     * @param  string  $content  Binary content
     * @param  array<string, mixed>  $headers  Response headers
     */
    public static function fromHttpResponse(string $content, array $headers = []): self
    {
        // Header names are case-insensitive; filename* (RFC 5987) is not decoded, so it gives null.
        $headers = array_change_key_case($headers);
        $contentType = $headers['content-type'] ?? 'application/zip';

        $filename = null;
        $contentDisposition = $headers['content-disposition'] ?? null;
        if (is_array($contentDisposition)) {
            $contentDisposition = $contentDisposition[0] ?? null;
        }
        if ($contentDisposition && preg_match('/(?:^|;)\s*filename\s*=\s*(["\']?)([^"\';\n]*)/i', $contentDisposition, $matches)) {
            // ANAF's suggestion is untrusted: never let it name a path.
            $name = basename(str_replace('\\', '/', $matches[2]));
            $filename = in_array($name, ['', '.', '..'], true) ? null : $name;
        }

        return new self(
            content: $content,
            contentType: is_array($contentType) ? $contentType[0] : $contentType,
            filename: $filename,
            contentLength: strlen($content),
        );
    }

    /**
     * Save the content to a file.
     *
     * @param  string  $path  File path to save to
     * @return bool True on success, false on failure
     */
    public function saveTo(string $path): bool
    {
        return file_put_contents($path, $this->content) !== false;
    }

    /**
     * Get the content as a stream resource.
     *
     * @return resource|false
     */
    public function getStream()
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return false;
        }

        $bytesWritten = fwrite($stream, $this->content);
        if ($bytesWritten === false || $bytesWritten !== strlen($this->content)) {
            fclose($stream);

            return false;
        }

        rewind($stream);

        return $stream;
    }
}

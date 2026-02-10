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

        /** Suggested filename from Content-Disposition header */
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
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/zip';
        $rawLength = $headers['Content-Length'] ?? $headers['content-length'] ?? null;
        if (is_array($rawLength)) {
            $rawLength = $rawLength[0] ?? null;
        }
        $contentLength = $rawLength !== null ? (int) $rawLength : null;

        $filename = null;
        $contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? null;
        if (is_array($contentDisposition)) {
            $contentDisposition = $contentDisposition[0] ?? null;
        }
        if ($contentDisposition && preg_match('/filename[^;=\n]*=([\"\']?)([^\"\';\n]*)/', $contentDisposition, $matches)) {
            $filename = $matches[2];
        }

        return new self(
            content: $content,
            contentType: is_array($contentType) ? $contentType[0] : $contentType,
            filename: $filename,
            contentLength: $contentLength ?? strlen($content),
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

<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Response\DownloadResponseData;

describe('DownloadResponseData', function () {
    it('creates with required fields', function () {
        $response = new DownloadResponseData(
            content: 'binary content',
            contentType: 'application/zip',
        );

        expect($response->content)->toBe('binary content');
        expect($response->contentType)->toBe('application/zip');
        expect($response->filename)->toBeNull();
        expect($response->contentLength)->toBeNull();
    });

    it('creates with all fields', function () {
        $response = new DownloadResponseData(
            content: 'binary content',
            contentType: 'application/zip',
            filename: 'document.zip',
            contentLength: 1024,
        );

        expect($response->filename)->toBe('document.zip');
        expect($response->contentLength)->toBe(1024);
    });

    describe('fromHttpResponse', function () {
        it('parses headers correctly', function () {
            $response = DownloadResponseData::fromHttpResponse(
                'binary content',
                [
                    'Content-Type' => 'application/zip',
                    'Content-Length' => '1024',
                    'Content-Disposition' => 'attachment; filename="document.zip"',
                ]
            );

            expect($response->contentType)->toBe('application/zip');
            expect($response->contentLength)->toBe(1024);
            expect($response->filename)->toBe('document.zip');
        });

        it('handles lowercase headers', function () {
            $response = DownloadResponseData::fromHttpResponse(
                'content',
                [
                    'content-type' => 'application/xml',
                    'content-length' => '500',
                ]
            );

            expect($response->contentType)->toBe('application/xml');
            expect($response->contentLength)->toBe(500);
        });

        it('defaults to application/zip', function () {
            $response = DownloadResponseData::fromHttpResponse('content', []);

            expect($response->contentType)->toBe('application/zip');
        });

        it('calculates content length from content', function () {
            $response = DownloadResponseData::fromHttpResponse('test content', []);

            expect($response->contentLength)->toBe(12); // strlen('test content')
        });

        it('handles array content type', function () {
            $response = DownloadResponseData::fromHttpResponse(
                'content',
                ['Content-Type' => ['application/xml']]
            );

            expect($response->contentType)->toBe('application/xml');
        });
    });

    describe('saveTo', function () {
        it('saves content to file', function () {
            $response = new DownloadResponseData(
                content: 'test file content',
                contentType: 'text/plain',
            );

            $tempFile = sys_get_temp_dir().'/test_download_'.uniqid().'.txt';

            try {
                $result = $response->saveTo($tempFile);

                expect($result)->toBeTrue();
                expect(file_exists($tempFile))->toBeTrue();
                expect(file_get_contents($tempFile))->toBe('test file content');
            } finally {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        });
    });

    describe('getStream', function () {
        it('returns readable stream', function () {
            $response = new DownloadResponseData(
                content: 'stream content',
                contentType: 'application/octet-stream',
            );

            $stream = $response->getStream();

            expect(is_resource($stream))->toBeTrue();
            expect(stream_get_contents($stream))->toBe('stream content');

            fclose($stream);
        });

        it('writes complete content to stream', function () {
            // Bug fix: fwrite() result is now checked to ensure all bytes were written
            $longContent = str_repeat('x', 10000);
            $response = new DownloadResponseData(
                content: $longContent,
                contentType: 'application/octet-stream',
            );

            $stream = $response->getStream();

            expect(is_resource($stream))->toBeTrue();
            $readContent = stream_get_contents($stream);
            expect(strlen($readContent))->toBe(10000);
            expect($readContent)->toBe($longContent);

            fclose($stream);
        });
    });
});

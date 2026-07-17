<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Response\ListMessagesResponseData;
use BeeCoded\EFacturaSdk\Data\Response\MessageDetailsData;
use BeeCoded\EFacturaSdk\Data\Response\PaginatedMessagesResponseData;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use BeeCoded\EFacturaSdk\Data\Response\UploadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\ValidationResultData;
use BeeCoded\EFacturaSdk\Enums\ExecutionStatus;
use BeeCoded\EFacturaSdk\Enums\UploadStatusValue;

describe('UploadResponseData', function () {
    it('creates from successful ANAF response', function () {
        $response = UploadResponseData::fromAnafResponse([
            'ExecutionStatus' => 0,
            'dateResponse' => '2024-03-15T10:30:00',
            'index_incarcare' => '12345',
        ]);

        expect($response->executionStatus)->toBe(ExecutionStatus::Success);
        expect($response->dateResponse)->toBe('2024-03-15T10:30:00');
        expect($response->indexIncarcare)->toBe('12345');
        expect($response->errors)->toBeNull();
    });

    it('creates from error ANAF response', function () {
        $response = UploadResponseData::fromAnafResponse([
            'ExecutionStatus' => 1,
            'Errors' => ['Invalid XML format'],
        ]);

        expect($response->executionStatus)->toBe(ExecutionStatus::Error);
        expect($response->errors)->toBe(['Invalid XML format']);
    });

    it('defaults to Error when ExecutionStatus is missing', function () {
        $response = UploadResponseData::fromAnafResponse([]);

        expect($response->executionStatus)->toBe(ExecutionStatus::Error);
    });

    it('defaults to Error for invalid ExecutionStatus', function () {
        $response = UploadResponseData::fromAnafResponse(['ExecutionStatus' => 99]);

        expect($response->executionStatus)->toBe(ExecutionStatus::Error);
    });

    it('isSuccessful returns true for success', function () {
        $response = UploadResponseData::fromAnafResponse(['ExecutionStatus' => 0]);

        expect($response->isSuccessful())->toBeTrue();
        expect($response->isFailed())->toBeFalse();
    });

    it('isFailed returns true for error', function () {
        $response = UploadResponseData::fromAnafResponse(['ExecutionStatus' => 1]);

        expect($response->isFailed())->toBeTrue();
        expect($response->isSuccessful())->toBeFalse();
    });
});

describe('StatusResponseData', function () {
    it('creates from successful ANAF response', function () {
        $response = StatusResponseData::fromAnafResponse([
            'stare' => 'ok',
            'id_descarcare' => '67890',
        ]);

        expect($response->stare)->toBe(UploadStatusValue::Ok);
        expect($response->idDescarcare)->toBe('67890');
        expect($response->errors)->toBeNull();
    });

    it('creates from in-progress response', function () {
        $response = StatusResponseData::fromAnafResponse([
            'stare' => 'in prelucrare',
        ]);

        expect($response->stare)->toBe(UploadStatusValue::InProgress);
        expect($response->isInProgress())->toBeTrue();
    });

    it('creates from failed response', function () {
        $response = StatusResponseData::fromAnafResponse([
            'stare' => 'nok',
            'Errors' => ['Processing failed'],
        ]);

        expect($response->stare)->toBe(UploadStatusValue::Failed);
        expect($response->isFailed())->toBeTrue();
    });

    it('isReady returns true for ok status', function () {
        $response = StatusResponseData::fromAnafResponse(['stare' => 'ok']);

        expect($response->isReady())->toBeTrue();
    });

    it('handles missing stare', function () {
        $response = StatusResponseData::fromAnafResponse([]);

        expect($response->stare)->toBeNull();
        expect($response->isReady())->toBeFalse();
        expect($response->isFailed())->toBeFalse();
        expect($response->isInProgress())->toBeFalse();
    });

    describe('ANAF 200 + {"eroare"} responses', function () {
        // ANAF answers an unknown/rejected id_incarcare with HTTP 200 and an "eroare"
        // body rather than a status. /descarcare is guarded against exactly this
        // (guardDownloadBody); stareMesaj was not, and dropped the message entirely.

        it('surfaces the eroare message through errors', function () {
            $response = StatusResponseData::fromAnafResponse([
                'eroare' => 'Nu exista niciun mesaj cu id-ul=123',
            ]);

            expect($response->errors)->toBe(['Nu exista niciun mesaj cu id-ul=123']);
        });

        it('leaves stare unrecognisable so callers do not read it as a verdict', function () {
            // Load-bearing for the wrapper package: an "eroare" body means ANAF told us
            // nothing about the document, which is NOT the same as ANAF rejecting it on
            // its merits (stare=nok). Mapping it to Failed would mark a possibly-filed
            // invoice as validation-failed. The wrapper keys its Indeterminate parking
            // on all three predicates being false; keep them that way.
            $response = StatusResponseData::fromAnafResponse([
                'eroare' => 'Nu exista niciun mesaj cu id-ul=123',
            ]);

            expect($response->stare)->toBeNull();
            expect($response->isReady())->toBeFalse();
            expect($response->isFailed())->toBeFalse();
            expect($response->isInProgress())->toBeFalse();
        });

        it('reports the error via hasAnafError only when one is present', function () {
            expect(StatusResponseData::fromAnafResponse(['eroare' => 'boom'])->hasAnafError())->toBeTrue();
            expect(StatusResponseData::fromAnafResponse(['stare' => 'ok'])->hasAnafError())->toBeFalse();
            expect(StatusResponseData::fromAnafResponse([])->hasAnafError())->toBeFalse();
        });

        it('does not let eroare displace a real Errors list', function () {
            $response = StatusResponseData::fromAnafResponse([
                'stare' => 'nok',
                'Errors' => ['Real validation error'],
                'eroare' => 'secondary',
            ]);

            expect($response->stare)->toBe(UploadStatusValue::Failed);
            expect($response->errors)->toBe(['Real validation error']);
        });

        it('ignores a non-string eroare rather than corrupting errors', function () {
            $response = StatusResponseData::fromAnafResponse(['eroare' => ['nested' => 'thing']]);

            expect($response->errors)->toBeNull();
        });
    });
});

describe('MessageDetailsData', function () {
    it('creates from ANAF response', function () {
        $message = MessageDetailsData::fromAnafResponse([
            'id' => '12345',
            'cif' => '12345678',
            'data_creare' => '2024-03-15',
            'tip' => 'FACTURA TRIMISA',
            'detalii' => 'Invoice details',
            'id_solicitare' => 'REQ-001',
        ]);

        expect($message->id)->toBe('12345');
        expect($message->cif)->toBe('12345678');
        expect($message->dataCreare)->toBe('2024-03-15');
        expect($message->tip)->toBe('FACTURA TRIMISA');
        expect($message->detalii)->toBe('Invoice details');
        expect($message->idSolicitare)->toBe('REQ-001');
    });

    it('handles missing fields with empty strings', function () {
        $message = MessageDetailsData::fromAnafResponse([]);

        expect($message->id)->toBe('');
        expect($message->cif)->toBe('');
        expect($message->dataCreare)->toBe('');
        expect($message->tip)->toBe('');
        expect($message->detalii)->toBe('');
        expect($message->idSolicitare)->toBe('');
    });
});

describe('ListMessagesResponseData', function () {
    it('creates from ANAF response with messages', function () {
        $response = ListMessagesResponseData::fromAnafResponse([
            'mesaje' => [
                [
                    'id' => '1',
                    'cif' => '12345678',
                    'data_creare' => '2024-03-15',
                    'tip' => 'T',
                    'detalii' => 'Details',
                    'id_solicitare' => 'REQ-1',
                ],
            ],
            'serial' => 'ABC123',
            'cui' => '12345678',
            'titlu' => 'Message List',
        ]);

        expect($response->hasMessages())->toBeTrue();
        expect($response->getMessageCount())->toBe(1);
        expect($response->serial)->toBe('ABC123');
    });

    it('handles empty messages', function () {
        $response = ListMessagesResponseData::fromAnafResponse([
            'mesaje' => [],
        ]);

        expect($response->hasMessages())->toBeFalse();
        expect($response->getMessageCount())->toBe(0);
    });

    it('filters non-array items from mesaje', function () {
        $response = ListMessagesResponseData::fromAnafResponse([
            'mesaje' => [
                'invalid_string',
                ['id' => '1', 'cif' => '123', 'data_creare' => '2024-01-01', 'tip' => 'T', 'detalii' => 'D', 'id_solicitare' => 'R1'],
            ],
        ]);

        expect($response->getMessageCount())->toBe(1);
    });

    it('handles error responses', function () {
        $response = ListMessagesResponseData::fromAnafResponse([
            'eroare' => 'Invalid request',
        ]);

        expect($response->hasError())->toBeTrue();
        expect($response->error)->toBe('Invalid request');
    });

    it('handles download error', function () {
        $response = ListMessagesResponseData::fromAnafResponse([
            'eroare_descarcare' => 'Download failed',
        ]);

        expect($response->hasError())->toBeTrue();
        expect($response->downloadError)->toBe('Download failed');
    });
});

describe('PaginatedMessagesResponseData', function () {
    it('creates from ANAF response with pagination', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'mesaje' => [
                ['id' => '1', 'cif' => '123', 'data_creare' => '2024-01-01', 'tip' => 'T', 'detalii' => 'D', 'id_solicitare' => 'R1'],
            ],
            'numar_inregistrari_in_pagina' => 1,
            'numar_total_inregistrari_per_pagina' => 100,
            'numar_total_inregistrari' => 50,
            'numar_total_pagini' => 1,
            'index_pagina_curenta' => 1,
        ]);

        expect($response->recordsInPage)->toBe(1);
        expect($response->recordsPerPage)->toBe(100);
        expect($response->totalRecords)->toBe(50);
        expect($response->totalPages)->toBe(1);
        expect($response->currentPage)->toBe(1);
    });

    it('hasNextPage returns true when more pages exist', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'numar_total_pagini' => 5,
            'index_pagina_curenta' => 2,
        ]);

        expect($response->hasNextPage())->toBeTrue();
    });

    it('hasNextPage returns false on last page', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'numar_total_pagini' => 5,
            'index_pagina_curenta' => 5,
        ]);

        expect($response->hasNextPage())->toBeFalse();
    });

    it('hasPreviousPage returns true when not on first page', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'index_pagina_curenta' => 2,
        ]);

        expect($response->hasPreviousPage())->toBeTrue();
    });

    it('hasPreviousPage returns false on first page', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'index_pagina_curenta' => 1,
        ]);

        expect($response->hasPreviousPage())->toBeFalse();
    });

    it('isFirstPage returns true for page 1', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'index_pagina_curenta' => 1,
        ]);

        expect($response->isFirstPage())->toBeTrue();
    });

    it('isLastPage returns true when on last page', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'numar_total_pagini' => 3,
            'index_pagina_curenta' => 3,
        ]);

        expect($response->isLastPage())->toBeTrue();
    });

    it('isLastPage returns true when pagination info is missing', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([]);

        expect($response->isLastPage())->toBeTrue();
    });

    it('isFirstPage returns false when pagination info is missing', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([]);

        expect($response->isFirstPage())->toBeFalse();
    });

    it('isFirstPage returns false when currentPage is null', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'numar_total_pagini' => 5,
            // index_pagina_curenta is missing (null)
        ]);

        expect($response->isFirstPage())->toBeFalse();
    });

    it('hasNextPage returns false when currentPage is null', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'numar_total_pagini' => 5,
            // index_pagina_curenta is missing
        ]);

        expect($response->hasNextPage())->toBeFalse();
    });

    it('hasPreviousPage returns false when currentPage is null', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            // index_pagina_curenta is missing
        ]);

        expect($response->hasPreviousPage())->toBeFalse();
    });

    it('handles error responses', function () {
        $response = PaginatedMessagesResponseData::fromAnafResponse([
            'eroare' => 'Invalid request',
        ]);

        expect($response->hasError())->toBeTrue();
    });
});

describe('ValidationResultData', function () {
    it('can contain validation errors', function () {
        $result = new ValidationResultData(
            valid: false,
            errors: ['Field X is required', 'Invalid format for Y'],
        );

        expect($result->valid)->toBeFalse();
        expect($result->errors)->toHaveCount(2);
    });
});

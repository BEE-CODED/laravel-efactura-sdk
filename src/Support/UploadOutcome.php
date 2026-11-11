<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

use BeeCoded\EFacturaSdk\Data\Response\UploadResponseData;
use BeeCoded\EFacturaSdk\Enums\ExecutionStatus;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeKind;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeReason;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\RequestNotSentException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\ApiClients\BaseApiClient;

/**
 * What one uploadDocument()/uploadB2CDocument() call means for the document.
 *
 * Classify the call's answer with fromResponse() and anything the call itself
 * threw with fromThrowable(); a caller classifies its own earlier stages (building,
 * storing, choosing the company) itself. Only Accepted carries an index. Uncertain
 * means ANAF may hold the document: reconcile before sending it again.
 */
final readonly class UploadOutcome
{
    public function __construct(
        public UploadOutcomeKind $kind,
        public ?UploadOutcomeReason $reason = null,
        public ?string $index = null,
        public ?int $retryAfterSeconds = null,
        /** ANAF's own words for a refusal, untranslated; null when it gave none */
        public ?string $providerText = null,
    ) {}

    /**
     * An index means ANAF took the document in, whatever its status says. An explicit
     * ExecutionStatus 1 without an index is a refusal; anything else is uncertain.
     */
    public static function fromResponse(UploadResponseData $response): self
    {
        $index = $response->indexIncarcare;

        if ($index !== null && preg_match('/\A[0-9]{1,64}\z/', $index) === 1) {
            return new self(UploadOutcomeKind::Accepted, index: $index);
        }

        if ($index === null && $response->executionStatus === ExecutionStatus::Error) {
            return new self(UploadOutcomeKind::Refused, UploadOutcomeReason::FailedWithoutIndex, providerText: $response->errors[0] ?? null);
        }

        return new self(UploadOutcomeKind::Uncertain);
    }

    /**
     * Classify a throwable raised by the upload call itself.
     */
    public static function fromThrowable(\Throwable $e): self
    {
        return match (true) {
            $e instanceof RequestNotSentException => new self(UploadOutcomeKind::NotSent, UploadOutcomeReason::Transport),
            // A provider refusal carries the SDK's estimate, not a wait ANAF asked for.
            $e instanceof RateLimitExceededException => new self(UploadOutcomeKind::NotSent, UploadOutcomeReason::RateLimited, retryAfterSeconds: $e->fromProvider ? null : $e->retryAfterSeconds),
            $e instanceof ApiException && $e->statusCode === 429 => new self(UploadOutcomeKind::NotSent, UploadOutcomeReason::RateLimited),
            // 408 is a timeout: ANAF may have filed the document.
            $e instanceof ApiException && $e->statusCode >= 400 && $e->statusCode < 500 && $e->statusCode !== 408 => new self(
                UploadOutcomeKind::Refused,
                UploadOutcomeReason::Rejected,
                providerText: $e->getMessage() === BaseApiClient::NO_ERROR_MESSAGE ? null : $e->getMessage(),
            ),
            $e instanceof AuthenticationException => new self(
                UploadOutcomeKind::NotSent,
                in_array($e->getCode(), [400, 401], true) ? UploadOutcomeReason::GrantRefused : UploadOutcomeReason::Transient,
            ),
            $e instanceof ValidationException => new self(UploadOutcomeKind::Refused, UploadOutcomeReason::LocalRefusal),
            // 5xx, an unreadable 2xx, a transport failure that may have delivered, anything unknown.
            default => new self(UploadOutcomeKind::Uncertain),
        };
    }
}

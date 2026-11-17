<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Support\DateHelper;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;
use Spatie\LaravelData\Data;

/**
 * A preceding invoice reference (BG-3): the number (BT-25) and optional issue date (BT-26)
 * of an invoice that a credit note or corrected invoice refers to.
 */
class PrecedingInvoiceData extends Data
{
    // Declared rather than promoted so the date accepts any CarbonInterface while storing a
    // concrete Carbon, exactly as InvoiceData does (order and defaults are load-bearing there).
    public string $number;

    #[WithCastAndTransformer(KeepDateInput::class)]
    public Carbon|string|null $issueDate = null;

    public function __construct(string $number, CarbonInterface|string|null $issueDate = null)
    {
        $this->number = $number;
        $this->issueDate = $issueDate instanceof CarbonInterface && ! $issueDate instanceof Carbon
            ? Carbon::instance($issueDate)
            : $issueDate;
    }

    /**
     * Return the issue date as a copy, or null if omitted.
     *
     * @throws \InvalidArgumentException If the date string is blank, unparseable or impossible (2024-02-30, 24:00)
     */
    public function getIssueDateAsCarbon(): ?Carbon
    {
        if ($this->issueDate === null || $this->issueDate instanceof Carbon) {
            return $this->issueDate?->copy();
        }

        try {
            return DateHelper::parse($this->issueDate);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid preceding invoice issue date format: {$this->issueDate}", 0, $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Builders;

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Support\AddressSanitizer;
use Sabre\Xml\Service as XmlService;
use Sabre\Xml\Writer;

/**
 * UBL 2.1 Invoice XML Builder for ANAF e-Factura.
 *
 * Generates compliant UBL 2.1 XML invoices following the Romanian CIUS-RO specification.
 * This builder handles all validation, tax grouping, and XML generation.
 */
class InvoiceBuilder
{
    /**
     * UBL 2.1 Invoice namespace.
     */
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    /**
     * Common Aggregate Components namespace.
     */
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /**
     * Common Basic Components namespace.
     */
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * CIUS-RO Customization ID for Romanian e-Factura.
     */
    private const UBL_CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1';

    /**
     * PEPPOL Profile ID.
     */
    private const UBL_PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    /**
     * Default currency code.
     */
    private const DEFAULT_CURRENCY = 'RON';

    /**
     * Default country code.
     */
    private const DEFAULT_COUNTRY_CODE = 'RO';

    /**
     * Default unit code (each).
     */
    private const DEFAULT_UNIT_CODE = 'EA';

    /**
     * VAT tax scheme ID.
     */
    private const VAT_SCHEME_ID = 'VAT';

    /**
     * Build a UBL 2.1 XML invoice from the provided invoice data.
     *
     * @param  InvoiceData  $input  The invoice data to convert to XML
     * @return string The generated UBL 2.1 XML string
     *
     * @throws ValidationException If the invoice data is invalid
     */
    public function buildInvoiceXml(InvoiceData $input): string
    {
        $this->validateInvoiceInput($input);

        $currency = $input->currency ?: self::DEFAULT_CURRENCY;
        $isSupplierVatPayer = $input->supplier->isVatPayer;

        // Group lines by tax percentage
        $taxGroups = $this->groupLinesByTax($input->lines, $isSupplierVatPayer);

        // Calculate totals
        $lineExtensionAmount = 0.0;
        $taxExclusiveAmount = 0.0;
        $taxInclusiveAmount = 0.0;
        $totalTaxAmount = 0.0;

        foreach ($input->lines as $line) {
            $lineExtensionAmount += $this->calculateLineExtension($line);
        }

        foreach ($taxGroups as $group) {
            $totalTaxAmount += $group['taxAmount'];
        }

        $taxExclusiveAmount = $lineExtensionAmount;
        $taxInclusiveAmount = $taxExclusiveAmount + $totalTaxAmount;

        // Build XML using Sabre\Xml
        $service = new XmlService;
        $service->namespaceMap = [
            self::NS_INVOICE => '',
            self::NS_CAC => 'cac',
            self::NS_CBC => 'cbc',
        ];

        $writer = $service->getWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        // Start Invoice element
        $writer->startElement('{'.self::NS_INVOICE.'}Invoice');

        // Write header elements
        $this->writeElement($writer, self::NS_CBC, 'CustomizationID', self::UBL_CUSTOMIZATION_ID);
        $this->writeElement($writer, self::NS_CBC, 'ProfileID', self::UBL_PROFILE_ID);
        $this->writeElement($writer, self::NS_CBC, 'ID', $input->invoiceNumber);
        $this->writeElement($writer, self::NS_CBC, 'IssueDate', $input->getIssueDateAsCarbon()->format('Y-m-d'));

        if ($input->dueDate !== null) {
            $this->writeElement($writer, self::NS_CBC, 'DueDate', $input->getDueDateAsCarbon()->format('Y-m-d'));
        }

        $this->writeElement($writer, self::NS_CBC, 'InvoiceTypeCode', $input->getInvoiceTypeCode()->value);
        $this->writeElement($writer, self::NS_CBC, 'DocumentCurrencyCode', $currency);

        // Write supplier party
        $this->buildPartyXml($writer, 'AccountingSupplierParty', $input->supplier, $isSupplierVatPayer);

        // Write customer party
        $this->buildPartyXml($writer, 'AccountingCustomerParty', $input->customer, $input->customer->isVatPayer);

        // Write payment means if IBAN is provided
        if ($input->paymentIban !== null && $input->paymentIban !== '') {
            $this->buildPaymentMeansXml($writer, $input->paymentIban);
        }

        // Write tax total
        $this->buildTaxTotalXml($writer, $taxGroups, $totalTaxAmount, $currency);

        // Write monetary total
        $this->buildLegalMonetaryTotalXml(
            $writer,
            $lineExtensionAmount,
            $taxExclusiveAmount,
            $taxInclusiveAmount,
            $currency
        );

        // Write invoice lines
        $lineId = 1;
        foreach ($input->lines as $line) {
            $this->buildInvoiceLineXml($writer, $line, $lineId, $isSupplierVatPayer, $currency);
            $lineId++;
        }

        $writer->endElement(); // Invoice

        return $writer->outputMemory();
    }

    /**
     * Validate the invoice input data.
     *
     * @throws ValidationException If validation fails
     */
    private function validateInvoiceInput(InvoiceData $input): void
    {
        if (empty($input->invoiceNumber)) {
            throw new ValidationException('Invoice number is required');
        }

        if (empty($input->issueDate)) {
            throw new ValidationException('Issue date is required');
        }

        $this->validateParty($input->supplier, 'Supplier');
        $this->validateParty($input->customer, 'Customer');

        if (empty($input->lines)) {
            throw new ValidationException('At least one invoice line is required');
        }

        foreach ($input->lines as $index => $line) {
            $this->validateLine($line, $index);
        }
    }

    /**
     * Validate party (supplier or customer) data.
     *
     * @throws ValidationException If validation fails
     */
    private function validateParty(PartyData $party, string $role): void
    {
        if (empty($party->registrationName)) {
            throw new ValidationException("{$role} registration name is required");
        }

        if (empty($party->companyId)) {
            throw new ValidationException("{$role} company ID (CIF/CUI) is required");
        }

        $this->validateAddress($party->address, $role);
    }

    /**
     * Validate address data.
     *
     * @throws ValidationException If validation fails
     */
    private function validateAddress(AddressData $address, string $role): void
    {
        if (empty($address->street)) {
            throw new ValidationException("{$role} street address is required");
        }

        if (empty($address->city)) {
            throw new ValidationException("{$role} city is required");
        }

        if (empty($address->postalZone)) {
            throw new ValidationException("{$role} postal code is required");
        }
    }

    /**
     * Validate invoice line data.
     *
     * @throws ValidationException If validation fails
     */
    private function validateLine(InvoiceLineData $line, int $index): void
    {
        $lineNum = $index + 1;

        if (empty($line->name)) {
            throw new ValidationException("Line {$lineNum}: Item name is required");
        }

        if ($line->quantity <= 0) {
            throw new ValidationException("Line {$lineNum}: Quantity must be greater than zero");
        }

        if ($line->unitPrice < 0) {
            throw new ValidationException("Line {$lineNum}: Unit price cannot be negative");
        }

        if ($line->taxPercent < 0 || $line->taxPercent > 100) {
            throw new ValidationException("Line {$lineNum}: Tax percent must be between 0 and 100");
        }
    }

    /**
     * Group invoice lines by tax percentage for tax subtotals.
     *
     * Tax calculation approach: Accumulate unrounded taxable amounts per group,
     * then calculate tax once on the group total and round only at the end.
     * This prevents double-rounding discrepancies where sum(line taxes) != group tax.
     *
     * @param  InvoiceLineData[]  $lines  The invoice lines
     * @param  bool  $isSupplierVatPayer  Whether the supplier is a VAT payer
     * @return array<int, array{taxPercent: float, taxCategoryId: TaxCategoryId, taxableAmount: float, taxAmount: float}> Grouped tax data
     */
    private function groupLinesByTax(array $lines, bool $isSupplierVatPayer): array
    {
        /** @var array<string, array{taxPercent: float, taxCategoryId: TaxCategoryId, taxableAmount: float, taxAmount: float}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $taxPercent = $line->taxPercent;
            $key = (string) $taxPercent;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'taxPercent' => $taxPercent,
                    'taxCategoryId' => $this->getTaxCategory($taxPercent, $isSupplierVatPayer),
                    'taxableAmount' => 0.0,
                    'taxAmount' => 0.0,
                ];
            }

            // Accumulate line amounts without intermediate rounding
            $lineAmount = $this->calculateLineExtension($line);
            $groups[$key]['taxableAmount'] += $lineAmount;
        }

        // Calculate tax on group totals and round once at the end
        // This ensures sum(line taxes) matches group tax subtotal
        foreach ($groups as &$group) {
            $group['taxableAmount'] = round($group['taxableAmount'], 2);
            // Calculate tax on the rounded taxable amount, then round the tax
            $group['taxAmount'] = round($group['taxableAmount'] * ($group['taxPercent'] / 100), 2);
        }

        // If no groups exist (empty invoice), add a default zero-tax group
        if (empty($groups)) {
            $groups['0'] = [
                'taxPercent' => 0.0,
                'taxCategoryId' => $isSupplierVatPayer ? TaxCategoryId::ZeroRated : TaxCategoryId::NotSubject,
                'taxableAmount' => 0.0,
                'taxAmount' => 0.0,
            ];
        }

        return array_values($groups);
    }

    /**
     * Determine the tax category based on tax percent and VAT payer status.
     *
     * @param  float  $taxPercent  The tax percentage
     * @param  bool  $isVatPayer  Whether the party is a VAT payer
     * @return TaxCategoryId The determined tax category
     */
    private function getTaxCategory(float $taxPercent, bool $isVatPayer): TaxCategoryId
    {
        // If not a VAT payer, the tax category is "Not subject" (O)
        if (! $isVatPayer) {
            return TaxCategoryId::NotSubject;
        }

        // For VAT payers:
        // - Zero percent -> Zero-rated (Z)
        // - Greater than zero -> Standard (S)
        // Use epsilon comparison for floating-point safety (less than 0.01%)
        if (abs($taxPercent) < 0.01) {
            return TaxCategoryId::ZeroRated;
        }

        return TaxCategoryId::Standard;
    }

    /**
     * Calculate line extension amount (quantity * unit price).
     */
    private function calculateLineExtension(InvoiceLineData $line): float
    {
        return round($line->quantity * $line->unitPrice, 2);
    }

    /**
     * Write a simple element with a namespace.
     */
    private function writeElement(Writer $writer, string $namespace, string $localName, string $value): void
    {
        $writer->writeElement('{'.$namespace.'}'.$localName, $value);
    }

    /**
     * Write an element with attributes.
     *
     * @param  array<string, string>  $attributes  Element attributes
     */
    private function writeElementWithAttributes(
        Writer $writer,
        string $namespace,
        string $localName,
        string $value,
        array $attributes
    ): void {
        $writer->startElement('{'.$namespace.'}'.$localName);
        foreach ($attributes as $attrName => $attrValue) {
            $writer->writeAttribute($attrName, $attrValue);
        }
        $writer->text($value);
        $writer->endElement();
    }

    /**
     * Build XML for a party (supplier or customer).
     */
    private function buildPartyXml(Writer $writer, string $tagName, PartyData $party, bool $isVatPayer): void
    {
        $writer->startElement('{'.self::NS_CAC.'}'.$tagName);
        $writer->startElement('{'.self::NS_CAC.'}Party');

        // Postal Address
        $this->buildPostalAddressXml($writer, $party->address);

        // Party Tax Scheme (VAT identification)
        $writer->startElement('{'.self::NS_CAC.'}PartyTaxScheme');

        // Normalize VAT number (ensure RO prefix for Romanian VAT payers)
        $companyId = $this->normalizeVatNumber($party->companyId, $party->address->countryCode);
        $this->writeElement($writer, self::NS_CBC, 'CompanyID', $companyId);

        $writer->startElement('{'.self::NS_CAC.'}TaxScheme');
        $this->writeElement($writer, self::NS_CBC, 'ID', self::VAT_SCHEME_ID);
        $writer->endElement(); // TaxScheme

        $writer->endElement(); // PartyTaxScheme

        // Party Legal Entity
        $writer->startElement('{'.self::NS_CAC.'}PartyLegalEntity');
        $this->writeElement($writer, self::NS_CBC, 'RegistrationName', $party->registrationName);
        $this->writeElement($writer, self::NS_CBC, 'CompanyID', $party->companyId);
        $writer->endElement(); // PartyLegalEntity

        $writer->endElement(); // Party
        $writer->endElement(); // AccountingSupplierParty/AccountingCustomerParty
    }

    /**
     * Build postal address XML.
     */
    private function buildPostalAddressXml(Writer $writer, AddressData $address): void
    {
        $writer->startElement('{'.self::NS_CAC.'}PostalAddress');

        $this->writeElement($writer, self::NS_CBC, 'StreetName', $address->street);
        $this->writeElement($writer, self::NS_CBC, 'CityName', $address->city);
        $this->writeElement($writer, self::NS_CBC, 'PostalZone', $address->postalZone);

        // Handle county/subdivision - sanitize for Romanian addresses
        $countrySubdivision = $this->sanitizeCountyOrSector($address);
        if ($countrySubdivision !== null) {
            $this->writeElement($writer, self::NS_CBC, 'CountrySubentity', $countrySubdivision);
        }

        // Country
        $countryCode = $address->countryCode ?: self::DEFAULT_COUNTRY_CODE;
        $writer->startElement('{'.self::NS_CAC.'}Country');
        $this->writeElement($writer, self::NS_CBC, 'IdentificationCode', $countryCode);
        $writer->endElement(); // Country

        $writer->endElement(); // PostalAddress
    }

    /**
     * Sanitize county or extract Bucharest sector.
     */
    private function sanitizeCountyOrSector(AddressData $address): ?string
    {
        // First check if this is a Bucharest address and try to extract sector
        if ($address->county !== null && AddressSanitizer::isBucharest($address->county)) {
            // Try to get sector from county field first
            $sector = AddressSanitizer::sanitizeBucharestSector($address->county);
            if ($sector !== null && $sector !== 'RO-B') {
                return $sector;
            }

            // Try to extract sector from street address
            $sector = AddressSanitizer::sanitizeBucharestSector($address->street);
            if ($sector !== null && $sector !== 'RO-B') {
                return $sector;
            }

            // Try to extract sector from city
            $sector = AddressSanitizer::sanitizeBucharestSector($address->city);
            if ($sector !== null && $sector !== 'RO-B') {
                return $sector;
            }

            // Fallback to RO-B if Bucharest but no sector found
            return 'RO-B';
        }

        // For non-Bucharest Romanian addresses, sanitize county
        if ($address->county !== null && $address->countryCode === self::DEFAULT_COUNTRY_CODE) {
            $sanitized = AddressSanitizer::sanitizeCounty($address->county);
            if ($sanitized !== null) {
                return $sanitized;
            }
        }

        return $address->county;
    }

    /**
     * Normalize VAT number with country prefix.
     */
    private function normalizeVatNumber(string $vatNumber, string $countryCode): string
    {
        $vatNumber = trim($vatNumber);
        $countryCode = strtoupper($countryCode ?: self::DEFAULT_COUNTRY_CODE);

        // If VAT number already starts with country code, return as-is
        if (str_starts_with(strtoupper($vatNumber), $countryCode)) {
            return strtoupper($vatNumber);
        }

        // Add country code prefix
        return $countryCode.$vatNumber;
    }

    /**
     * Build payment means XML.
     */
    private function buildPaymentMeansXml(Writer $writer, string $iban): void
    {
        $writer->startElement('{'.self::NS_CAC.'}PaymentMeans');

        // Payment means code 30 = Credit transfer (bank transfer)
        $this->writeElement($writer, self::NS_CBC, 'PaymentMeansCode', '30');

        $writer->startElement('{'.self::NS_CAC.'}PayeeFinancialAccount');
        $this->writeElement($writer, self::NS_CBC, 'ID', $iban);
        $writer->endElement(); // PayeeFinancialAccount

        $writer->endElement(); // PaymentMeans
    }

    /**
     * Build tax total XML with subtotals.
     *
     * @param  array<int, array{taxPercent: float, taxCategoryId: TaxCategoryId, taxableAmount: float, taxAmount: float}>  $taxGroups
     */
    private function buildTaxTotalXml(Writer $writer, array $taxGroups, float $totalTaxAmount, string $currency): void
    {
        $writer->startElement('{'.self::NS_CAC.'}TaxTotal');

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxAmount',
            $this->formatAmount($totalTaxAmount),
            ['currencyID' => $currency]
        );

        // Tax subtotals by tax rate
        foreach ($taxGroups as $group) {
            $writer->startElement('{'.self::NS_CAC.'}TaxSubtotal');

            $this->writeElementWithAttributes(
                $writer,
                self::NS_CBC,
                'TaxableAmount',
                $this->formatAmount($group['taxableAmount']),
                ['currencyID' => $currency]
            );

            $this->writeElementWithAttributes(
                $writer,
                self::NS_CBC,
                'TaxAmount',
                $this->formatAmount($group['taxAmount']),
                ['currencyID' => $currency]
            );

            $writer->startElement('{'.self::NS_CAC.'}TaxCategory');
            $this->writeElement($writer, self::NS_CBC, 'ID', $group['taxCategoryId']->value);
            $this->writeElement($writer, self::NS_CBC, 'Percent', $this->formatAmount($group['taxPercent']));

            $writer->startElement('{'.self::NS_CAC.'}TaxScheme');
            $this->writeElement($writer, self::NS_CBC, 'ID', self::VAT_SCHEME_ID);
            $writer->endElement(); // TaxScheme

            $writer->endElement(); // TaxCategory
            $writer->endElement(); // TaxSubtotal
        }

        $writer->endElement(); // TaxTotal
    }

    /**
     * Build legal monetary total XML.
     */
    private function buildLegalMonetaryTotalXml(
        Writer $writer,
        float $lineExtensionAmount,
        float $taxExclusiveAmount,
        float $taxInclusiveAmount,
        string $currency
    ): void {
        $writer->startElement('{'.self::NS_CAC.'}LegalMonetaryTotal');

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'LineExtensionAmount',
            $this->formatAmount($lineExtensionAmount),
            ['currencyID' => $currency]
        );

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxExclusiveAmount',
            $this->formatAmount($taxExclusiveAmount),
            ['currencyID' => $currency]
        );

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxInclusiveAmount',
            $this->formatAmount($taxInclusiveAmount),
            ['currencyID' => $currency]
        );

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'PayableAmount',
            $this->formatAmount($taxInclusiveAmount),
            ['currencyID' => $currency]
        );

        $writer->endElement(); // LegalMonetaryTotal
    }

    /**
     * Build invoice line XML.
     */
    private function buildInvoiceLineXml(
        Writer $writer,
        InvoiceLineData $line,
        int $lineId,
        bool $isSupplierVatPayer,
        string $currency
    ): void {
        $writer->startElement('{'.self::NS_CAC.'}InvoiceLine');

        // Line ID
        $this->writeElement($writer, self::NS_CBC, 'ID', (string) ($line->id ?? $lineId));

        // Invoiced quantity
        $unitCode = $line->unitCode ?: self::DEFAULT_UNIT_CODE;
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'InvoicedQuantity',
            $this->formatAmount($line->quantity),
            ['unitCode' => $unitCode]
        );

        // Line extension amount
        $lineAmount = $this->calculateLineExtension($line);
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'LineExtensionAmount',
            $this->formatAmount($lineAmount),
            ['currencyID' => $currency]
        );

        // Item
        $writer->startElement('{'.self::NS_CAC.'}Item');

        if ($line->description !== null && $line->description !== '') {
            $this->writeElement($writer, self::NS_CBC, 'Description', $line->description);
        }

        $this->writeElement($writer, self::NS_CBC, 'Name', $line->name);

        // Classified tax category
        $writer->startElement('{'.self::NS_CAC.'}ClassifiedTaxCategory');
        $taxCategory = $this->getTaxCategory($line->taxPercent, $isSupplierVatPayer);
        $this->writeElement($writer, self::NS_CBC, 'ID', $taxCategory->value);
        $this->writeElement($writer, self::NS_CBC, 'Percent', $this->formatAmount($line->taxPercent));

        $writer->startElement('{'.self::NS_CAC.'}TaxScheme');
        $this->writeElement($writer, self::NS_CBC, 'ID', self::VAT_SCHEME_ID);
        $writer->endElement(); // TaxScheme

        $writer->endElement(); // ClassifiedTaxCategory
        $writer->endElement(); // Item

        // Price
        $writer->startElement('{'.self::NS_CAC.'}Price');
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'PriceAmount',
            $this->formatAmount($line->unitPrice),
            ['currencyID' => $currency]
        );
        $writer->endElement(); // Price

        $writer->endElement(); // InvoiceLine
    }

    /**
     * Format amount with 2 decimal places.
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}

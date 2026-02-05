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
     * UBL 2.1 Credit Note namespace.
     */
    private const NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

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
     * Build a UBL 2.1 XML invoice or credit note from the provided invoice data.
     *
     * Automatically generates the correct document type based on InvoiceTypeCode:
     * - 381 (CreditNote) -> UBL CreditNote document
     * - 380, 384, 389, 751 -> UBL Invoice document
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
        $isCreditNote = $input->getInvoiceTypeCode()->isCreditNote();

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

        // Determine root namespace based on document type
        $rootNamespace = $isCreditNote ? self::NS_CREDIT_NOTE : self::NS_INVOICE;
        $rootElement = $isCreditNote ? 'CreditNote' : 'Invoice';

        // Build XML using Sabre\Xml
        $service = new XmlService;
        $service->namespaceMap = [
            $rootNamespace => '',
            self::NS_CAC => 'cac',
            self::NS_CBC => 'cbc',
        ];

        $writer = $service->getWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        // Start root element (Invoice or CreditNote)
        $writer->startElement('{'.$rootNamespace.'}'.$rootElement);

        // Write header elements
        $this->writeElement($writer, self::NS_CBC, 'CustomizationID', self::UBL_CUSTOMIZATION_ID);
        $this->writeElement($writer, self::NS_CBC, 'ProfileID', self::UBL_PROFILE_ID);
        $this->writeElement($writer, self::NS_CBC, 'ID', $input->invoiceNumber);
        $this->writeElement($writer, self::NS_CBC, 'IssueDate', $input->getIssueDateAsCarbon()->format('Y-m-d'));

        if ($input->dueDate !== null) {
            /** @var \Carbon\Carbon $dueDate PHPStan: guaranteed non-null by guard clause above */
            $dueDate = $input->getDueDateAsCarbon();
            $this->writeElement($writer, self::NS_CBC, 'DueDate', $dueDate->format('Y-m-d'));
        }

        // Type code element name differs between Invoice and CreditNote
        $typeCodeElement = $isCreditNote ? 'CreditNoteTypeCode' : 'InvoiceTypeCode';
        $this->writeElement($writer, self::NS_CBC, $typeCodeElement, $input->getInvoiceTypeCode()->value);
        $this->writeElement($writer, self::NS_CBC, 'DocumentCurrencyCode', $currency);

        // BR-RO-030: If currency is not RON, TaxCurrencyCode must be RON
        if ($currency !== self::DEFAULT_CURRENCY) {
            $this->writeElement($writer, self::NS_CBC, 'TaxCurrencyCode', self::DEFAULT_CURRENCY);
        }

        // Write supplier party
        $this->buildPartyXml($writer, 'AccountingSupplierParty', $input->supplier, $isSupplierVatPayer);

        // Write customer party
        $this->buildPartyXml($writer, 'AccountingCustomerParty', $input->customer, $input->customer->isVatPayer);

        // Write payment means if IBAN is provided
        if ($input->paymentIban !== null && $input->paymentIban !== '') {
            $this->buildPaymentMeansXml($writer, $input->paymentIban);
        }

        // Write tax total(s)
        // BR-RO-030: When currency is not RON, we need two TaxTotal elements:
        // 1. TaxAmount in document currency
        // 2. TaxAmount in RON (tax accounting currency)
        $this->buildTaxTotalXml($writer, $taxGroups, $totalTaxAmount, $currency);

        // Add second TaxTotal in RON for non-RON invoices
        if ($currency !== self::DEFAULT_CURRENCY) {
            // Note: For proper multi-currency support, an exchange rate would be needed.
            // For now, we output the same amount - users should ensure correct RON amounts.
            $this->buildTaxTotalInAccountingCurrency($writer, $totalTaxAmount);
        }

        // Write monetary total
        $this->buildLegalMonetaryTotalXml(
            $writer,
            $lineExtensionAmount,
            $taxExclusiveAmount,
            $taxInclusiveAmount,
            $currency
        );

        // Write invoice/credit note lines
        $lineId = 1;
        foreach ($input->lines as $line) {
            $this->buildInvoiceLineXml($writer, $line, $lineId, $isSupplierVatPayer, $currency, $isCreditNote);
            $lineId++;
        }

        $writer->endElement(); // Invoice or CreditNote

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

        // BR-RO-010: Invoice number must contain at least one digit
        if (! preg_match('/[0-9]/', $input->invoiceNumber)) {
            throw new ValidationException('Invoice number must contain at least one numeric character (BR-RO-010)');
        }

        // BR-RO-L200: Invoice number max 200 characters
        if (mb_strlen($input->invoiceNumber) > 200) {
            throw new ValidationException('Invoice number must not exceed 200 characters (BR-RO-L200)');
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

        // BR-RO-L200: Registration name max 200 characters
        if (mb_strlen($party->registrationName) > 200) {
            throw new ValidationException("{$role} registration name must not exceed 200 characters (BR-RO-L200)");
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

        // BR-RO-L150: Address line 1 max 150 characters
        if (mb_strlen($address->street) > 150) {
            throw new ValidationException("{$role} street address must not exceed 150 characters (BR-RO-L150)");
        }

        if (empty($address->city)) {
            throw new ValidationException("{$role} city is required");
        }

        // BR-RO-L050: City name max 50 characters
        if (mb_strlen($address->city) > 50) {
            throw new ValidationException("{$role} city must not exceed 50 characters (BR-RO-L050)");
        }

        if (empty($address->postalZone)) {
            throw new ValidationException("{$role} postal code is required");
        }

        // BR-RO-L020: Postal code max 20 characters
        if (mb_strlen($address->postalZone) > 20) {
            throw new ValidationException("{$role} postal code must not exceed 20 characters (BR-RO-L020)");
        }

        // BR-RO-110/111: Romanian addresses require CountrySubentity (county)
        if ($address->countryCode === 'RO' && empty($address->county)) {
            throw new ValidationException("{$role} county is required for Romanian addresses (BR-RO-110)");
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

        // BR-RO-L100: Item name max 100 characters
        if (mb_strlen($line->name) > 100) {
            throw new ValidationException("Line {$lineNum}: Item name must not exceed 100 characters (BR-RO-L100)");
        }

        // BR-RO-L200: Item description max 200 characters
        if ($line->description !== null && mb_strlen($line->description) > 200) {
            throw new ValidationException("Line {$lineNum}: Item description must not exceed 200 characters (BR-RO-L200)");
        }

        // Allow negative quantities for credit notes, but not zero
        if ($line->quantity == 0) {
            throw new ValidationException("Line {$lineNum}: Quantity cannot be zero");
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
            // Round to 2 decimal places to avoid floating-point precision issues
            // e.g., 19.0 vs 19.00000001 producing different keys
            $key = (string) round($taxPercent, 2);

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

        // Handle county/subdivision - sanitize for Romanian addresses
        $countrySubdivision = $this->sanitizeCountyOrSector($address);

        // BR-RO-100/101: For Bucharest (RO-B), city must be SECTOR1-6
        $cityName = $this->sanitizeCityName($address, $countrySubdivision);
        $this->writeElement($writer, self::NS_CBC, 'CityName', $cityName);

        $this->writeElement($writer, self::NS_CBC, 'PostalZone', $address->postalZone);

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
     * Sanitize city name for UBL output.
     *
     * BR-RO-100/101: For Bucharest (RO-B), city must be SECTOR1-6.
     */
    private function sanitizeCityName(AddressData $address, ?string $countrySubdivision): string
    {
        // Only apply sector formatting for Bucharest addresses
        if ($countrySubdivision !== 'RO-B') {
            return $address->city;
        }

        // Try to extract sector number from city or county
        $sectorNumber = AddressSanitizer::extractBucharestSectorNumber($address->city)
            ?? ($address->county !== null ? AddressSanitizer::extractBucharestSectorNumber($address->county) : null);

        if ($sectorNumber !== null) {
            return 'SECTOR'.$sectorNumber;
        }

        // If we can't determine sector, return city as-is (will fail ANAF validation)
        // This is intentional - user needs to provide valid sector info
        return $address->city;
    }

    /**
     * Sanitize county or extract Bucharest sector.
     */
    private function sanitizeCountyOrSector(AddressData $address): ?string
    {
        // Check if this is a Bucharest address - all Bucharest addresses use RO-B
        // (Bucharest sectors are NOT part of ISO 3166-2:RO, so all map to RO-B)
        if ($address->county !== null && AddressSanitizer::isBucharest($address->county)) {
            return 'RO-B';
        }

        // For non-Bucharest Romanian addresses, sanitize county to ISO 3166-2:RO format
        // ANAF requires ISO codes (BR-RO-111 rule) - non-compliant values cause validation errors
        if ($address->county !== null && $address->countryCode === self::DEFAULT_COUNTRY_CODE) {
            $sanitized = AddressSanitizer::sanitizeCounty($address->county);
            if ($sanitized !== null) {
                return $sanitized;
            }

            // For Romanian addresses, fail fast if county cannot be mapped to ISO code
            // This prevents ANAF BR-RO-111 validation errors at submission time
            throw new ValidationException(
                sprintf(
                    'County "%s" could not be mapped to a valid ISO 3166-2:RO code. '.
                    'Romanian addresses require valid county codes (e.g., "RO-AB" for Alba, "RO-B" for Bucharest).',
                    $address->county
                )
            );
        }

        // For non-Romanian addresses, return county as-is (ANAF doesn't enforce ISO codes for foreign countries)
        return $address->county;
    }

    /**
     * Normalize VAT number with country prefix.
     */
    private function normalizeVatNumber(string $vatNumber, ?string $countryCode): string
    {
        $vatNumber = trim($vatNumber);
        $countryCode = strtoupper($countryCode ?? self::DEFAULT_COUNTRY_CODE);

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
     * Build tax total XML in accounting currency (RON) for non-RON invoices.
     *
     * BR-RO-030: When DocumentCurrencyCode is not RON, TaxCurrencyCode must be RON
     * and a second TaxTotal with RON amounts is required.
     *
     * Note: This outputs only the total tax amount in RON without subtotals.
     * For accurate multi-currency invoicing, the caller should provide
     * the correct RON amount based on the applicable exchange rate.
     */
    private function buildTaxTotalInAccountingCurrency(Writer $writer, float $taxAmount): void
    {
        $writer->startElement('{'.self::NS_CAC.'}TaxTotal');

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxAmount',
            $this->formatAmount($taxAmount),
            ['currencyID' => self::DEFAULT_CURRENCY]
        );

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
     * Build invoice or credit note line XML.
     */
    private function buildInvoiceLineXml(
        Writer $writer,
        InvoiceLineData $line,
        int $lineId,
        bool $isSupplierVatPayer,
        string $currency,
        bool $isCreditNote = false
    ): void {
        // Element names differ between Invoice and CreditNote
        $lineElement = $isCreditNote ? 'CreditNoteLine' : 'InvoiceLine';
        $quantityElement = $isCreditNote ? 'CreditedQuantity' : 'InvoicedQuantity';

        $writer->startElement('{'.self::NS_CAC.'}'.$lineElement);

        // Line ID
        $this->writeElement($writer, self::NS_CBC, 'ID', (string) ($line->id ?? $lineId));

        // Invoiced/Credited quantity
        $unitCode = $line->unitCode ?: self::DEFAULT_UNIT_CODE;
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            $quantityElement,
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

        $writer->endElement(); // InvoiceLine or CreditNoteLine
    }

    /**
     * Format amount with 2 decimal places.
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}

<?php

return [
    // Invoice header
    'invoice_number_required' => 'Invoice number is required',
    'invoice_number_must_contain_digit' => 'Invoice number must contain at least one numeric character (BR-RO-010)',
    'invoice_number_max_length' => 'Invoice number must not exceed 200 characters (BR-RO-L200)',
    'issue_date_required' => 'Issue date is required',
    'at_least_one_line_required' => 'At least one invoice line is required',

    // Party (uses :role — Supplier/Customer)
    'party_registration_name_required' => ':Role registration name is required',
    'party_registration_name_max_length' => ':Role registration name must not exceed 200 characters (BR-RO-L200)',
    'party_company_id_required' => ':Role company ID (CIF/CUI) is required',
    'party_street_required' => ':Role street address is required',
    'party_street_max_length' => ':Role street address must not exceed 150 characters (BR-RO-L150)',
    'party_city_required' => ':Role city is required',
    'party_city_max_length' => ':Role city must not exceed 50 characters (BR-RO-L050)',
    'party_postal_code_max_length' => ':Role postal code must not exceed 20 characters (BR-RO-L020)',
    'party_county_required_ro' => ':Role county is required for Romanian addresses (BR-RO-110)',

    // Line items (uses :lineNum)
    'line_item_name_required' => 'Line :lineNum: Item name is required',
    'line_item_name_max_length' => 'Line :lineNum: Item name must not exceed 100 characters (BR-RO-L100)',
    'line_item_description_max_length' => 'Line :lineNum: Item description must not exceed 200 characters (BR-RO-L200)',
    'line_quantity_cannot_be_zero' => 'Line :lineNum: Quantity cannot be zero',
    'line_unit_price_not_negative' => 'Line :lineNum: Unit price cannot be negative',
    'line_tax_percent_range' => 'Line :lineNum: Tax percent must be between 0 and 100',

    // Billing reference
    'preceding_invoice_number_max_length' => 'The preceding invoice number must not exceed 200 characters (BR-RO-L200)',

    // County mapping
    'county_invalid_iso_code' => 'County ":county" could not be mapped to a valid ISO 3166-2:RO code. Romanian addresses require valid county codes (e.g., "RO-AB" for Alba, "RO-B" for Bucharest).',
];

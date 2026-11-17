<?php

declare(strict_types=1);

return [
    // Invoice header
    'invoice_number_required' => 'Invoice number is required',
    'invoice_number_must_contain_digit' => 'Invoice number must contain at least one numeric character (BR-RO-010)',
    'invoice_number_max_length' => 'Invoice number must not exceed 200 characters (BR-RO-L200)',
    'issue_date_required' => 'Issue date is required',
    'issue_date_invalid' => 'The issue date (BT-2) ":date" is not a valid date',
    'due_date_invalid' => 'The due date (BT-9) ":date" is not a valid date',
    'tax_point_date_invalid' => 'The VAT point date (BT-7) ":date" is not a valid date',
    'at_least_one_line_required' => 'At least one invoice line is required',
    'currency_code_invalid' => 'The currency code must be three uppercase letters (ISO 4217, BR-CL-04)',
    'due_date_required_for_positive_payable' => 'An invoice with a positive amount due must have a due date (BR-CO-25)',

    // Input values
    'amount_invalid' => ':field must be a finite amount with at most :scale decimal places; a float may differ from that only by binary representation noise',
    'element_blank' => 'The :element element would be filed blank, which ANAF rejects',
    'xml_text_invalid' => ':field contains invalid UTF-8 or a character XML cannot carry (control characters other than tab, line feed and carriage return, U+FFFE, U+FFFF)',

    // Party (:Role from roles)
    'roles' => ['Supplier' => 'supplier', 'Customer' => 'customer', 'Delivery' => 'delivery'],
    'party_registration_name_required' => ':Role registration name is required',
    'party_registration_name_max_length' => ':Role registration name must not exceed 200 characters (BR-RO-L200)',
    'party_registration_number_max_length' => ':Role registration number must not exceed 1000 characters (BR-RO-L1000)',
    'party_company_id_required' => ':Role company ID (CIF/CUI) is required',
    'party_street_required' => ':Role street address is required',
    'party_street_max_length' => ':Role street address must not exceed 150 characters (BR-RO-L150)',
    'party_city_required' => ':Role city is required',
    'party_city_max_length' => ':Role city must not exceed 50 characters (BR-RO-L050)',
    'party_postal_code_max_length' => ':Role postal code must not exceed 20 characters (BR-RO-L020)',
    'party_county_required_ro' => ':Role county is required for Romanian addresses (BR-RO-110)',
    'country_code_invalid' => ':Role country code must be two uppercase letters (ISO 3166-1 alpha-2, BR-CL-14)',

    // Line items (uses :lineNum)
    'line_item_name_required' => 'Line :lineNum: Item name is required',
    'line_item_name_max_length' => 'Line :lineNum: Item name must not exceed 100 characters (BR-RO-L100)',
    'line_item_description_max_length' => 'Line :lineNum: Item description must not exceed 200 characters (BR-RO-L200)',
    'line_unit_code_invalid' => 'Line :lineNum: Unit code ":unitCode" is not a UN/ECE Recommendation 20/21 code (BR-CL-23)',
    'line_prefixed' => 'Line :lineNum: :message',
    'line_quantity_cannot_be_zero' => 'Line :lineNum: Quantity cannot be zero',
    'line_unit_price_not_negative' => 'Line :lineNum: Unit price cannot be negative',
    'line_tax_percent_range' => 'Line :lineNum: Tax percent must be between 0 and 100',
    'line_tax_not_allowed_for_non_vat_payer' => 'Line :lineNum: A supplier that is not registered for VAT cannot charge VAT (BR-O-09)',
    'line_tax_amount_must_be_zero_for_zero_rate' => 'Line :lineNum: A zero-rated line cannot carry a VAT amount (BR-Z-09)',

    // VAT categories (uses :category)
    'explicit_category_supplier_must_be_vat_payer' => 'Lines in VAT category :category require a supplier registered for VAT; category O cannot be combined with :category.',
    'explicit_category_tax_must_be_zero' => 'Lines in VAT category :category must declare an exactly zero raw VAT rate and VAT amount (BR-AE-05/09, BR-E-05/09, BR-G-05/09, BR-IC-05/09).',
    'tax_category_inconsistent' => 'The explicit VAT category must agree with the supplier VAT status and line rate.',
    'category_vat_identifier_required' => ':Role must be registered for VAT and supply a usable VAT identifier for VAT category :category (BR-AE-02, BR-E-02, BR-G-02, BR-IC-02).',
    'zero_vat_group_tax_amount_must_be_zero' => 'The VAT amount of the :category breakdown must be exactly zero once its lines are summed and rounded (BR-Z-09, BR-O-09, BR-E-09, BR-G-09, BR-IC-09).',
    'intra_community_delivery_required' => 'An intra-community supply (VAT category K) needs a delivery with an actual delivery date and a delivery address (BR-IC-11, BR-IC-12).',

    // VAT exemption reasons (BT-120/BT-121)
    'exemption_reason_category_invalid' => 'An exemption reason can only be given for VAT categories E, G, K and AE, not :category.',
    'exemption_reason_duplicate' => 'VAT category :category has more than one exemption reason.',
    'exemption_reason_without_lines' => 'An exemption reason is given for VAT category :category, but no line uses that category.',
    'exemption_reason_code_fixed' => 'The exemption reason code for VAT category :category is always :code.',
    'exemption_reason_code_invalid' => 'Exemption reason code ":code" is not in the VATEX code list (BR-CL-22).',
    'exemption_reason_required' => 'Lines in VAT category E need an exemption reason code or text (BR-E-10).',
    'exemption_reason_text_max_length' => 'The exemption reason text for VAT category :category must not exceed 100 characters (BR-RO-L100).',

    // Billing reference (BG-3)
    'preceding_invoice_number_max_length' => 'The preceding invoice number must not exceed 200 characters (BR-RO-L200)',
    'preceding_invoice_number_required' => 'Each preceding invoice reference needs an invoice number (BR-55)',
    'preceding_invoices_max_count' => 'An invoice can reference at most 500 preceding invoices (BR-RO-A500)',
    'preceding_invoice_conflict' => 'precedingInvoiceNumber is deprecated: when precedingInvoices is also set, it must name its only entry',
    'preceding_invoice_issue_date_invalid' => 'The preceding invoice issue date (BT-26) ":date" is not a valid date',

    // Notes, VAT point date code and delivery
    'notes_max_count' => 'An invoice can carry at most 20 notes',
    'note_required' => 'Note :index must not be empty',
    'note_max_length' => 'Note :index must not exceed 300 characters (BR-RO-L300)',
    'vat_point_date_code_invalid' => 'The VAT point date code must be 3, 35 or 432 (BT-8)',
    'vat_point_date_code_with_tax_point_date' => 'The VAT point date (BT-7) and the VAT point date code (BT-8) are mutually exclusive (BR-CO-03)',
    'delivery_county_required' => 'The delivery address needs a county or region',
    'actual_delivery_date_invalid' => 'The actual delivery date (BT-72) ":date" is not a valid date',

    // Tax accounting currency (BT-111)
    'tax_amount_ron_required' => 'A :currency invoice must declare its total VAT in RON: set taxAmountRon to the converted amount (BT-111). ANAF cannot verify the conversion, so an incorrect value would be filed as a true statement of VAT owed (BR-RO-030, BR-53).',
    'tax_amount_ron_not_allowed_for_ron' => 'taxAmountRon must not be set on a RON invoice: the VAT total is already stated in RON, and a second RON tax total is not permitted (BR-CO-15).',
    'tax_amount_ron_sign_mismatch' => 'taxAmountRon must have the same sign as the invoice VAT total: a credited amount cannot be declared as collected VAT, or vice versa (BT-111).',
    'tax_amount_ron_must_be_zero' => 'A document without standard-rated lines must declare exactly zero taxAmountRon: it owes no VAT in RON (BT-111).',

    // County mapping
    'bucharest_sector_required' => 'A Bucharest address needs its sector: set bucharestSector or name the sector in the city or county (city ":city", BR-RO-100/101)',
    'bucharest_sector_invalid' => 'bucharestSector must be 1 to 6 and is only valid on a Bucharest (RO-B) address',
    'county_invalid_iso_code' => 'County ":county" could not be mapped to a valid ISO 3166-2:RO code. Romanian addresses require valid county codes (e.g., "RO-AB" for Alba, "RO-B" for Bucharest).',
];

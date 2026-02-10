<?php

declare(strict_types=1);

return [
    // Antet factură
    'invoice_number_required' => 'Numărul facturii este obligatoriu',
    'invoice_number_must_contain_digit' => 'Numărul facturii trebuie să conțină cel puțin un caracter numeric (BR-RO-010)',
    'invoice_number_max_length' => 'Numărul facturii nu trebuie să depășească 200 de caractere (BR-RO-L200)',
    'issue_date_required' => 'Data emiterii este obligatorie',
    'at_least_one_line_required' => 'Este necesară cel puțin o linie de factură',

    // Parte (folosește :role — Furnizor/Client)
    'party_registration_name_required' => 'Denumirea :role este obligatorie',
    'party_registration_name_max_length' => 'Denumirea :role nu trebuie să depășească 200 de caractere (BR-RO-L200)',
    'party_company_id_required' => 'CIF/CUI :role este obligatoriu',
    'party_street_required' => 'Adresa stradale :role este obligatorie',
    'party_street_max_length' => 'Adresa stradale :role nu trebuie să depășească 150 de caractere (BR-RO-L150)',
    'party_city_required' => 'Localitatea :role este obligatorie',
    'party_city_max_length' => 'Localitatea :role nu trebuie să depășească 50 de caractere (BR-RO-L050)',
    'party_postal_code_max_length' => 'Codul poștal :role nu trebuie să depășească 20 de caractere (BR-RO-L020)',
    'party_county_required_ro' => 'Județul :role este obligatoriu pentru adresele din România (BR-RO-110)',

    // Linii factură (folosește :lineNum)
    'line_item_name_required' => 'Linia :lineNum: Denumirea articolului este obligatorie',
    'line_item_name_max_length' => 'Linia :lineNum: Denumirea articolului nu trebuie să depășească 100 de caractere (BR-RO-L100)',
    'line_item_description_max_length' => 'Linia :lineNum: Descrierea articolului nu trebuie să depășească 200 de caractere (BR-RO-L200)',
    'line_quantity_cannot_be_zero' => 'Linia :lineNum: Cantitatea nu poate fi zero',
    'line_unit_price_not_negative' => 'Linia :lineNum: Prețul unitar nu poate fi negativ',
    'line_tax_percent_range' => 'Linia :lineNum: Procentul de taxă trebuie să fie între 0 și 100',

    // Referință facturare
    'preceding_invoice_number_max_length' => 'Numărul facturii precedente nu trebuie să depășească 200 de caractere (BR-RO-L200)',

    // Mapare județ
    'county_invalid_iso_code' => 'Județul ":county" nu a putut fi mapat la un cod ISO 3166-2:RO valid. Adresele din România necesită coduri de județ valide (ex: "RO-AB" pentru Alba, "RO-B" pentru București).',
];

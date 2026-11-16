<?php

declare(strict_types=1);

return [
    // Antet factură
    'invoice_number_required' => 'Numărul facturii este obligatoriu',
    'invoice_number_must_contain_digit' => 'Numărul facturii trebuie să conțină cel puțin un caracter numeric (BR-RO-010)',
    'invoice_number_max_length' => 'Numărul facturii nu trebuie să depășească 200 de caractere (BR-RO-L200)',
    'issue_date_required' => 'Data emiterii este obligatorie',
    'at_least_one_line_required' => 'Este necesară cel puțin o linie de factură',
    'lines_max_count' => 'O factură poate avea cel mult 999 de linii (BR-RO-A999)',
    'currency_code_invalid' => 'Codul monedei trebuie să fie format din trei litere mari (ISO 4217, BR-CL-04)',
    'due_date_required_for_positive_payable' => 'O factură cu o sumă de plată pozitivă trebuie să aibă o dată scadentă (BR-CO-25)',

    // Valori de intrare
    'amount_invalid' => ':field trebuie să fie o sumă finită cu cel mult :scale zecimale; o valoare float poate diferi de aceasta doar prin zgomotul reprezentării binare',
    'xml_text_invalid' => ':field conține UTF-8 invalid sau un caracter pe care XML nu îl poate transporta (caractere de control în afară de tab, linie nouă și retur de car, U+FFFE, U+FFFF)',

    // Parte (folosește :role — Furnizor/Client/Livrare)
    'party_registration_name_required' => 'Denumirea :role este obligatorie',
    'party_registration_name_max_length' => 'Denumirea :role nu trebuie să depășească 200 de caractere (BR-RO-L200)',
    'party_registration_number_max_length' => 'Numărul de înregistrare :role nu trebuie să depășească 1000 de caractere (BR-RO-L1000)',
    'party_company_id_required' => 'CIF/CUI :role este obligatoriu',
    'party_street_required' => 'Adresa stradale :role este obligatorie',
    'party_street_max_length' => 'Adresa stradale :role nu trebuie să depășească 150 de caractere (BR-RO-L150)',
    'party_city_required' => 'Localitatea :role este obligatorie',
    'party_city_max_length' => 'Localitatea :role nu trebuie să depășească 50 de caractere (BR-RO-L050)',
    'party_postal_code_max_length' => 'Codul poștal :role nu trebuie să depășească 20 de caractere (BR-RO-L020)',
    'party_county_required_ro' => 'Județul :role este obligatoriu pentru adresele din România (BR-RO-110)',
    'country_code_invalid' => 'Codul țării :role trebuie să fie format din două litere mari (ISO 3166-1 alpha-2, BR-CL-14)',

    // Linii factură (folosește :lineNum)
    'line_item_name_required' => 'Linia :lineNum: Denumirea articolului este obligatorie',
    'line_item_name_max_length' => 'Linia :lineNum: Denumirea articolului nu trebuie să depășească 100 de caractere (BR-RO-L100)',
    'line_item_description_max_length' => 'Linia :lineNum: Descrierea articolului nu trebuie să depășească 200 de caractere (BR-RO-L200)',
    'line_quantity_cannot_be_zero' => 'Linia :lineNum: Cantitatea nu poate fi zero',
    'line_unit_price_not_negative' => 'Linia :lineNum: Prețul unitar nu poate fi negativ',
    'line_tax_percent_range' => 'Linia :lineNum: Procentul de taxă trebuie să fie între 0 și 100',
    'line_tax_not_allowed_for_non_vat_payer' => 'Linia :lineNum: Un furnizor neînregistrat în scopuri de TVA nu poate factura TVA (BR-O-09)',
    'line_tax_amount_must_be_zero_for_zero_rate' => 'Linia :lineNum: O linie cu cotă zero de TVA nu poate avea o valoare a TVA (BR-Z-09)',

    // Categorii TVA (folosește :category)
    'explicit_category_supplier_must_be_vat_payer' => 'Liniile din categoria de TVA :category necesită un furnizor înregistrat în scopuri de TVA; categoria O nu poate fi combinată cu :category.',
    'explicit_category_tax_must_be_zero' => 'Liniile din categoria de TVA :category trebuie să declare o cotă și o valoare brută a TVA exact zero, înainte de rotunjire (BR-AE-05/09, BR-E-05/09, BR-G-05/09, BR-IC-05/09).',
    'tax_category_inconsistent' => 'Categoria explicită de TVA trebuie să corespundă statutului TVA al furnizorului și cotei liniei.',
    'category_vat_identifier_required' => ':Role trebuie să fie înregistrat în scopuri de TVA și să furnizeze un identificator TVA utilizabil pentru categoria de TVA :category (BR-AE-02, BR-E-02, BR-G-02, BR-IC-02).',
    'zero_vat_group_tax_amount_must_be_zero' => 'Valoarea TVA a defalcării pentru categoria :category trebuie să fie exact zero după însumarea și rotunjirea liniilor (BR-Z-09, BR-O-09, BR-E-09, BR-G-09, BR-IC-09).',
    'intra_community_delivery_required' => 'O livrare intracomunitară (categoria de TVA K) necesită o livrare cu data efectivă a livrării și adresa de livrare (BR-IC-11, BR-IC-12).',

    // Motive de scutire de TVA (BT-120/BT-121)
    'exemption_reason_category_invalid' => 'Un motiv de scutire poate fi dat doar pentru categoriile de TVA E, G, K și AE, nu pentru :category.',
    'exemption_reason_duplicate' => 'Categoria de TVA :category are mai multe motive de scutire.',
    'exemption_reason_without_lines' => 'Este dat un motiv de scutire pentru categoria de TVA :category, dar nicio linie nu folosește această categorie.',
    'exemption_reason_code_fixed' => 'Codul motivului de scutire pentru categoria de TVA :category este întotdeauna :code.',
    'exemption_reason_code_invalid' => 'Codul motivului de scutire ":code" nu se află în lista de coduri VATEX (BR-CL-22).',
    'exemption_reason_required' => 'Liniile din categoria de TVA E necesită un cod sau un text al motivului de scutire (BR-E-10).',
    'exemption_reason_text_max_length' => 'Textul motivului de scutire pentru categoria de TVA :category nu trebuie să depășească 100 de caractere (BR-RO-L100).',

    // Referință facturare (BG-3)
    'preceding_invoice_number_max_length' => 'Numărul facturii precedente nu trebuie să depășească 200 de caractere (BR-RO-L200)',
    'preceding_invoice_number_required' => 'Fiecare referință la o factură anterioară necesită numărul facturii (BR-55)',
    'preceding_invoices_max_count' => 'O factură poate face referire la cel mult 500 de facturi anterioare (BR-RO-A500)',
    'preceding_invoice_conflict' => 'precedingInvoiceNumber este învechit: când este setat și precedingInvoices, trebuie să numească singura sa intrare',
    'preceding_invoice_issue_date_invalid' => 'Data emiterii facturii anterioare (BT-26) ":date" nu este o dată validă',

    // Note, codul datei de exigibilitate a TVA și livrare
    'notes_max_count' => 'O factură poate conține cel mult 20 de note',
    'note_required' => 'Nota :index nu trebuie să fie goală',
    'note_max_length' => 'Nota :index nu trebuie să depășească 300 de caractere (BR-RO-L300)',
    'vat_point_date_code_invalid' => 'Codul datei de exigibilitate a TVA trebuie să fie 3, 35 sau 432 (BT-8)',
    'vat_point_date_code_with_tax_point_date' => 'Data de exigibilitate a TVA (BT-7) și codul datei de exigibilitate a TVA (BT-8) se exclud reciproc (BR-CO-03)',
    'delivery_county_required' => 'Adresa de livrare necesită un județ sau o regiune',
    'actual_delivery_date_invalid' => 'Data efectivă a livrării (BT-72) ":date" nu este o dată validă',

    // Moneda de contabilizare a TVA (BT-111)
    'tax_amount_ron_required' => 'O factură în :currency trebuie să declare valoarea totală a TVA în RON: setați taxAmountRon la suma convertită (BT-111). ANAF nu poate verifica conversia, așa că o valoare incorectă ar fi depusă ca declarație reală a TVA datorate (BR-RO-030, BR-53).',
    'tax_amount_ron_not_allowed_for_ron' => 'taxAmountRon nu trebuie setat pe o factură în RON: valoarea TVA este deja exprimată în RON, iar un al doilea total de TVA în RON nu este permis (BR-CO-15).',
    'tax_amount_ron_sign_mismatch' => 'taxAmountRon trebuie să aibă același semn ca totalul TVA al facturii: o sumă creditată nu poate fi declarată drept TVA colectată, și invers (BT-111).',
    'tax_amount_ron_must_be_zero' => 'Un document fără linii cu cotă standard trebuie să declare taxAmountRon exact zero: nu datorează TVA în RON (BT-111).',

    // Mapare județ
    'bucharest_sector_required' => 'O adresă din București necesită sectorul: setați bucharestSector sau precizați sectorul în localitate sau județ (localitatea ":city", BR-RO-100/101)',
    'bucharest_sector_invalid' => 'bucharestSector trebuie să fie între 1 și 6 și este valid doar pentru o adresă din București (RO-B)',
    'county_invalid_iso_code' => 'Județul ":county" nu a putut fi mapat la un cod ISO 3166-2:RO valid. Adresele din România necesită coduri de județ valide (ex: "RO-AB" pentru Alba, "RO-B" pentru București).',
];

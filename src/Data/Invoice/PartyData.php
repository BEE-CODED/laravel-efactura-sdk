<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/**
 * Party information (supplier or customer) for an invoice.
 *
 * Maps to TypeScript Party interface.
 */
class PartyData extends Data
{
    // Properties are declared here rather than promoted because the CONSTRUCTOR accepts a wider
    // type for $isVatPayer than the PROPERTY stores: the parameter is bool|string so that a
    // stray string is caught (see normaliseIsVatPayer()), while the property stays a plain bool
    // so laravel-data keeps inferring a `boolean` rule for it and consumers keep reading a real
    // bool. A promoted parameter cannot do this — its type IS the property's type.
    //
    // Two details are load-bearing, exactly as in InvoiceData:
    //
    //  - the declaration ORDER is the constructor's, because laravel-data builds its property
    //    list from ReflectionClass::getProperties(), which drives toArray()/toJson() key order;
    //  - $registrationNumber carries the constructor's DEFAULT, because laravel-data reads a
    //    non-promoted property's default from the property itself (DataPropertyFactory) rather
    //    than from the constructor signature. Without it the field would become "required" in
    //    getValidationRules() and validate() would reject payloads that omit it.
    //
    // Both are guarded by tests in tests/Unit/Data/Invoice/PartyDataTest.php.

    /** The legal name of the party as registered */
    public string $registrationName;

    /** CIF/CUI number (e.g., "49296198"). The builder handles RO prefix automatically for VAT payers. */
    public string $companyId;

    /** Address of the party */
    public AddressData $address;

    /**
     * Whether the party is registered for VAT. REQUIRED — deliberately no default.
     *
     * This is a declaration about the party's tax status, not a preference, and
     * it decides what gets filed: InvoiceBuilder::getTaxCategory() puts EVERY
     * line under VAT category "O" (Not subject to VAT) when this is false, and
     * omits the party's VAT identifier (BT-31 for the seller, BT-48 for the
     * buyer). A default would let a caller who simply forgot the flag file a
     * VAT-registered company as a non-payer — and because that document is
     * internally consistent, ANAF accepts it. There is no error to notice.
     *
     * Having no default makes the omission unrepresentable: direct construction
     * raises an ArgumentCountError, ::from() raises CannotCreateData, and the
     * #[Required] rule below turns it into a clean validation error.
     *
     * #[Required] is not redundant: laravel-data's BuiltInTypesRuleInferrer
     * infers only `boolean` for a bool property, which a MISSING key passes —
     * validation would let the payload through and the constructor would then
     * fail with an ArgumentCountError instead of a 422. The rule is also safe
     * on a bool: Laravel's validateRequired() rejects only null, '', empty
     * countables and empty Files, so an explicit `false` passes.
     */
    #[Required]
    public bool $isVatPayer;

    /** ONRC identifier / registration number (e.g., "J40/1234/2020") */
    public ?string $registrationNumber = null;

    /**
     * @param  bool|string  $isVatPayer  Whether the party is registered for VAT. Declared
     *                                   bool|string ONLY so that a mistaken string is caught
     *                                   rather than silently coerced; see normaliseIsVatPayer().
     */
    public function __construct(
        string $registrationName,
        string $companyId,
        AddressData $address,
        bool|string $isVatPayer,
        ?string $registrationNumber = null,
    ) {
        $this->registrationName = $registrationName;
        $this->companyId = $companyId;
        $this->address = $address;
        $this->isVatPayer = self::normaliseIsVatPayer($isVatPayer);
        $this->registrationNumber = $registrationNumber;
    }

    /**
     * Normalise the $isVatPayer constructor argument to a bool, rejecting anything that is not
     * an unambiguous declaration.
     *
     * The parameter is typed bool|string rather than bool to defuse v2's positional argument
     * order. v2's signature was:
     *
     *     (registrationName, companyId, address, ?string $registrationNumber = null, bool $isVatPayer = false)
     *
     * v3 made $isVatPayer required and moved it 5th -> 4th, so a v2 positional call — the
     * idiomatic shape, used by v2's own README and test helper — lands its registrationNumber
     * string on $isVatPayer. Against a plain `bool` parameter in COERCIVE mode (a caller file
     * without declare(strict_types=1), the Laravel application default) that string binds as
     * TRUE, with no error: a micro-enterprise that relied on v2's `false` default silently
     * becomes a VAT payer, every line moves from VAT category O to Z, and the party gains a
     * BT-31 seller VAT id it does not hold. The document is internally consistent, so the
     * schematron passes it and ANAF files it.
     *
     * A bool|string union closes that door because coercive mode only coerces when NO member of
     * the union matches exactly: a string matches `string` and arrives here intact, so the
     * silent case becomes loud, while a real true/false still binds to `bool` untouched.
     *
     * "1" and "0" must still construct, however. ::from()/validateAndCreate() hand the RAW
     * payload value straight to this constructor (laravel-data does not pre-cast — PHP's
     * coercive binding inside DataFromArrayResolver did the work when this parameter was a
     * plain bool), and Laravel's `boolean` rule — the rule inferred for the property — accepts
     * exactly true, false, 1, 0, "1" and "0". Rejecting those would let validateAndCreate()
     * pass validation and then explode on a payload the rule had just declared valid. An int
     * 1/0 arrives here as "1"/"0" because PHP's coercive binding prefers `string` over `bool`
     * when both are in the union.
     *
     * Every other string is rejected. That is stricter than the old plain-bool parameter, which
     * coerced "yes" to true on the unvalidated ::from() path — but that is the same silent-flip
     * failure reached through a different door, and the `boolean` rule rejects "yes" anyway.
     *
     * @throws \InvalidArgumentException If the argument is not an unambiguous boolean
     */
    private static function normaliseIsVatPayer(bool|string $isVatPayer): bool
    {
        if (is_bool($isVatPayer)) {
            return $isVatPayer;
        }

        return match ($isVatPayer) {
            '1' => true,
            '0' => false,
            default => throw new \InvalidArgumentException(sprintf(
                'PartyData::$isVatPayer must be a bool, received the string "%s". Note that v3 '
                .'moved $isVatPayer from the 5th constructor position to the 4th and removed its '
                .'`false` default, so a v2 positional call passes $registrationNumber here — left '
                .'to coerce, that string would bind as TRUE and silently file this party as a VAT '
                .'payer. Pass the declaration explicitly; named arguments are safest: '
                .'new PartyData(..., isVatPayer: false, registrationNumber: "%s").',
                $isVatPayer,
                $isVatPayer,
            )),
        };
    }
}

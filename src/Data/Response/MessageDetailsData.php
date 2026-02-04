<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Data\Response;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Single message details in message list.
 * Maps to TypeScript MessageDetails interface.
 */
class MessageDetailsData extends Data
{
    public function __construct(
        /** Download ID */
        public string $id,

        /** CIF number */
        public string $cif,

        /** Creation date */
        #[MapInputName('data_creare')]
        public string $dataCreare,

        /** Message type */
        public string $tip,

        /** Message details */
        public string $detalii,

        /** Request ID */
        #[MapInputName('id_solicitare')]
        public string $idSolicitare,
    ) {}

    /**
     * Create from ANAF API response item.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAnafResponse(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            cif: (string) ($data['cif'] ?? ''),
            dataCreare: (string) ($data['data_creare'] ?? ''),
            tip: (string) ($data['tip'] ?? ''),
            detalii: (string) ($data['detalii'] ?? ''),
            idSolicitare: (string) ($data['id_solicitare'] ?? ''),
        );
    }
}

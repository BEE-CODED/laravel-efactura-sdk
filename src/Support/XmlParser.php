<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

use BeeCoded\EFacturaSdk\Enums\ExecutionStatus;
use BeeCoded\EFacturaSdk\Enums\UploadStatusValue;
use BeeCoded\EFacturaSdk\Exceptions\XmlParsingException;
use Sabre\Xml\Reader;

/**
 * XML response parser for ANAF e-Factura API.
 *
 * ANAF returns XML responses for upload, status check, and other operations.
 * This utility provides consistent parsing and error extraction from these responses.
 */
final class XmlParser
{
    /**
     * Parse an upload response XML from ANAF.
     *
     * @param  string  $xml  The raw XML response
     * @return array{executionStatus: int, indexIncarcare: string|null, dateResponse: string|null, errors: string[]|null}
     *
     * @throws XmlParsingException If the XML cannot be parsed or has unexpected structure
     */
    public static function parseUploadResponse(string $xml): array
    {
        $parsed = self::parseXml($xml);

        if ($parsed === null) {
            throw new XmlParsingException('Failed to parse XML response', $xml);
        }

        $result = self::tryParseUploadStructure($parsed);

        if ($result !== null) {
            return $result;
        }

        throw new XmlParsingException('Unknown or unexpected XML response structure', $xml);
    }

    /**
     * Parse a status response XML from ANAF.
     *
     * @param  string  $xml  The raw XML response
     * @return array{stare: string|null, idDescarcare: string|null, errors: string[]|null}
     *
     * @throws XmlParsingException If the XML cannot be parsed or has unexpected structure
     */
    public static function parseStatusResponse(string $xml): array
    {
        $parsed = self::parseXml($xml);

        if ($parsed === null) {
            throw new XmlParsingException('Failed to parse XML response', $xml);
        }

        $result = self::tryParseStatusStructure($parsed);

        if ($result !== null) {
            return $result;
        }

        throw new XmlParsingException('Unknown or unexpected XML response structure', $xml);
    }

    /**
     * Extract error message from XML response.
     *
     * @param  string  $xml  The raw XML response
     * @return string|null The error message if found, null otherwise
     */
    public static function extractErrorMessage(string $xml): ?string
    {
        $parsed = self::parseXml($xml);

        if ($parsed === null) {
            return null;
        }

        return self::findErrorMessage($parsed);
    }

    /**
     * Parse XML string into an array structure.
     *
     * @param  string  $xml  The XML string to parse
     * @return array<string, mixed>|null Parsed array or null on failure
     */
    private static function parseXml(string $xml): ?array
    {
        $xml = trim($xml);

        if ($xml === '') {
            return null;
        }

        try {
            $reader = new Reader;
            $reader->xml($xml);

            // Parse into Clark notation array (returns array with name, value, attributes)
            $result = $reader->parse();

            // Convert to simpler structure
            return self::normalizeXmlArray($result);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Normalize Sabre/XML output to a simpler associative array.
     *
     * @param  array<string, mixed>  $data  The parsed XML data
     * @return array<string, mixed> Normalized array
     */
    private static function normalizeXmlArray(array $data): array
    {
        $result = [];

        // Handle root element
        $name = $data['name'] ?? null;
        $value = $data['value'] ?? null;

        if (is_string($name)) {
            $strippedName = self::stripNamespace($name);
            /** @var array<string, mixed> $attributes */
            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

            if (is_array($value) && $value !== []) {
                $result[$strippedName] = self::processChildren($value, $attributes);
            } elseif (! empty($attributes)) {
                // Handle elements with attributes (with or without text content)
                $content = ['@' => $attributes];
                if ($value !== null && $value !== '') {
                    $content['_'] = $value;
                }
                $result[$strippedName] = $content;
            } else {
                $result[$strippedName] = $value ?? '';
            }
        } elseif (! empty($data)) {
            // Fallback for other structures
            foreach ($data as $key => $item) {
                $stringKey = (string) $key;
                if (is_array($item)) {
                    /** @var array<string, mixed> $itemArray */
                    $itemArray = $item;
                    $result[$stringKey] = self::normalizeXmlArray($itemArray);
                } else {
                    $result[$stringKey] = $item;
                }
            }
        }

        return $result;
    }

    /**
     * Process child elements into an associative array.
     *
     * @param  array<int|string, mixed>  $children  Child elements
     * @param  array<string, mixed>  $parentAttributes  Parent element attributes
     * @return array<string, mixed> Processed children
     */
    private static function processChildren(array $children, array $parentAttributes = []): array
    {
        $result = [];

        if (! empty($parentAttributes)) {
            $result['@'] = $parentAttributes;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $childName = $child['name'] ?? null;
            if (! is_string($childName)) {
                continue;
            }

            $name = self::stripNamespace($childName);
            /** @var array<string, mixed> $attributes */
            $attributes = is_array($child['attributes'] ?? null) ? $child['attributes'] : [];
            $childValue = $child['value'] ?? null;

            if (is_array($childValue) && ! empty($childValue)) {
                $processed = self::processChildren($childValue, $attributes);
                if (isset($result[$name])) {
                    // Convert to array if multiple elements with same name
                    $existing = $result[$name];
                    if (! is_array($existing) || ! array_key_exists(0, $existing)) {
                        $result[$name] = [$existing];
                    }
                    /** @var array<int, mixed> $resultArray */
                    $resultArray = $result[$name];
                    $resultArray[] = $processed;
                    $result[$name] = $resultArray;
                } else {
                    $result[$name] = $processed;
                }
            } else {
                $value = $childValue ?? '';
                if (! empty($attributes)) {
                    $value = array_merge(['@' => $attributes], ['_' => $value]);
                }
                if (isset($result[$name])) {
                    $existing = $result[$name];
                    if (! is_array($existing) || ! array_key_exists(0, $existing)) {
                        $result[$name] = [$existing];
                    }
                    /** @var array<int, mixed> $resultArray */
                    $resultArray = $result[$name];
                    $resultArray[] = $value;
                    $result[$name] = $resultArray;
                } else {
                    $result[$name] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Strip namespace prefix from element name.
     *
     * @param  string  $name  Element name possibly with namespace
     * @return string Element name without namespace
     */
    private static function stripNamespace(string $name): string
    {
        // Handle Clark notation {namespace}localName
        if (preg_match('/^\{[^}]*\}(.+)$/', $name, $matches)) {
            return $matches[1];
        }

        // Handle prefix:localName
        if (str_contains($name, ':')) {
            $pos = strrpos($name, ':');

            return $pos !== false ? substr($name, $pos + 1) : $name;
        }

        return $name;
    }

    /**
     * Try to parse upload response structure.
     *
     * @param  array<string, mixed>  $doc  Parsed document
     * @return array{executionStatus: int, indexIncarcare: string|null, dateResponse: string|null, errors: string[]|null}|null
     */
    private static function tryParseUploadStructure(array $doc): ?array
    {
        // Look for header element (case-insensitive)
        $header = $doc['header'] ?? $doc['Header'] ?? null;

        if ($header === null || ! is_array($header)) {
            return null;
        }

        // Get content (may be direct or nested in array)
        /** @var array<string, mixed> $content */
        $content = isset($header[0]) && is_array($header[0]) ? $header[0] : $header;

        // Get attributes from @ key or direct properties
        /** @var array<string, mixed> $attributes */
        $attributes = isset($content['@']) && is_array($content['@']) ? $content['@'] : $content;

        // Check for ExecutionStatus
        if (! isset($attributes['ExecutionStatus'])) {
            return null;
        }

        $executionStatusRaw = $attributes['ExecutionStatus'];
        $statusValue = is_numeric($executionStatusRaw) ? (int) $executionStatusRaw : 0;

        $indexIncarcareRaw = $attributes['index_incarcare'] ?? null;
        $dateResponseRaw = $attributes['dateResponse'] ?? null;

        $result = [
            'executionStatus' => $statusValue,
            'indexIncarcare' => $indexIncarcareRaw !== null ? (is_scalar($indexIncarcareRaw) ? (string) $indexIncarcareRaw : null) : null,
            'dateResponse' => $dateResponseRaw !== null ? (is_scalar($dateResponseRaw) ? (string) $dateResponseRaw : null) : null,
            'errors' => null,
        ];

        // Extract errors if status indicates error
        if ($statusValue === ExecutionStatus::Error->value) {
            $errors = $content['Errors'] ?? $content['errors'] ?? null;
            if ($errors !== null) {
                $errorList = is_array($errors) ? $errors : [$errors];
                $result['errors'] = array_map(
                    fn ($err): string => self::findErrorMessage($err) ?? (is_string($err) ? $err : ''),
                    $errorList
                );
            }
        }

        return $result;
    }

    /**
     * Try to parse status response structure.
     *
     * @param  array<string, mixed>  $doc  Parsed document
     * @return array{stare: string|null, idDescarcare: string|null, errors: string[]|null}|null
     */
    private static function tryParseStatusStructure(array $doc): ?array
    {
        // Try header element first
        $header = $doc['header'] ?? $doc['Header'] ?? null;

        if ($header !== null && is_array($header)) {
            /** @var array<string, mixed> $content */
            $content = isset($header[0]) && is_array($header[0]) ? $header[0] : $header;
            /** @var array<string, mixed> $attributes */
            $attributes = isset($content['@']) && is_array($content['@']) ? $content['@'] : $content;

            // Check for stare or id_descarcare
            if (isset($attributes['stare']) || isset($attributes['id_descarcare'])) {
                $stareRaw = $attributes['stare'] ?? null;
                $idDescarcareRaw = $attributes['id_descarcare'] ?? null;

                return [
                    'stare' => $stareRaw !== null && is_scalar($stareRaw) ? (string) $stareRaw : null,
                    'idDescarcare' => $idDescarcareRaw !== null && is_scalar($idDescarcareRaw) ? (string) $idDescarcareRaw : null,
                    'errors' => null,
                ];
            }

            // Check for errors in header
            $errors = $content['Errors'] ?? $content['errors'] ?? $content['Error'] ?? $content['error'] ?? null;
            if ($errors !== null) {
                $errorList = is_array($errors) ? $errors : [$errors];
                $errorMessages = array_map(
                    fn ($err): string => self::findErrorMessage($err) ?? 'Operation failed',
                    $errorList
                );

                return [
                    'stare' => UploadStatusValue::Failed->value,
                    'idDescarcare' => null,
                    'errors' => $errorMessages,
                ];
            }
        }

        // Try alternative structures (Raspuns, response, etc.)
        $raspuns = $doc['Raspuns'] ?? $doc['response'] ?? $doc['Response'] ?? null;

        // Check for SOAP envelope structure
        if ($raspuns === null) {
            $envelope = $doc['Envelope'] ?? null;
            if (is_array($envelope)) {
                $body = $envelope['Body'] ?? null;
                if (is_array($body)) {
                    $raspuns = $body['Raspuns'] ?? null;
                }
            }
        }

        if ($raspuns !== null && is_array($raspuns)) {
            /** @var array<string, mixed> $content */
            $content = isset($raspuns[0]) && is_array($raspuns[0]) ? $raspuns[0] : $raspuns;

            if (isset($content['id_descarcare']) || isset($content['stare'])) {
                return [
                    'stare' => isset($content['stare']) ? self::extractTextValue($content['stare']) : null,
                    'idDescarcare' => isset($content['id_descarcare']) ? self::extractTextValue($content['id_descarcare']) : null,
                    'errors' => null,
                ];
            }

            $errorDetail = $content['Error'] ?? $content['eroare'] ?? null;
            if ($errorDetail !== null) {
                $errorMessage = is_array($errorDetail) && isset($errorDetail['mesaj'])
                    ? self::extractTextValue($errorDetail['mesaj'])
                    : self::extractTextValue($errorDetail);

                return [
                    'stare' => UploadStatusValue::Failed->value,
                    'idDescarcare' => null,
                    'errors' => [$errorMessage],
                ];
            }
        }

        return null;
    }

    /**
     * Extract text value from various element formats.
     *
     * @param  mixed  $element  The element to extract text from
     * @return string The extracted text
     */
    private static function extractTextValue(mixed $element): string
    {
        if (is_string($element)) {
            return $element;
        }

        if (is_array($element)) {
            // Check for first element
            $first = $element[0] ?? null;
            if ($first !== null && is_scalar($first)) {
                return (string) $first;
            }
            // Check for _ (text content in mixed content)
            $underscore = $element['_'] ?? null;
            if ($underscore !== null) {
                return is_scalar($underscore) ? (string) $underscore : '';
            }
        }

        if (is_scalar($element)) {
            return (string) $element;
        }

        return '';
    }

    /**
     * Recursively search for error message in parsed structure.
     *
     * @param  mixed  $obj  The object to search
     * @return string|null The error message if found
     */
    private static function findErrorMessage(mixed $obj): ?string
    {
        if (! is_array($obj)) {
            return is_string($obj) ? $obj : null;
        }

        // Direct errorMessage property
        if (isset($obj['errorMessage'])) {
            return is_scalar($obj['errorMessage']) ? (string) $obj['errorMessage'] : null;
        }

        // errorMessage in @ attributes
        $atSign = $obj['@'] ?? null;
        if (is_array($atSign) && isset($atSign['errorMessage'])) {
            return is_scalar($atSign['errorMessage']) ? (string) $atSign['errorMessage'] : null;
        }

        // Check mesaj (Romanian for "message")
        if (isset($obj['mesaj'])) {
            return is_scalar($obj['mesaj']) ? (string) $obj['mesaj'] : null;
        }

        // Search all keys
        foreach ($obj as $key => $value) {
            $stringKey = is_string($key) ? $key : (string) $key;

            // Check if key is or ends with errorMessage
            if ($stringKey === 'errorMessage' || str_ends_with($stringKey, 'errorMessage')) {
                return is_scalar($value) ? (string) $value : null;
            }

            // Recursively search nested objects
            if (is_array($value)) {
                $found = self::findErrorMessage($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}

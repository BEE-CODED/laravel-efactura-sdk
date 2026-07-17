<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Extracts the symbols our documentation claims exist, so DocsDriftTest can
 * check each one against PHP reality.
 *
 * Why this exists: the MCP suite asserts documentation *contains* a string
 * (`expect(apiReferenceContent["EFacturaClient"]).toContain("fromTokens")`).
 * That is circular — the docs are both the subject and the oracle, so a doc
 * naming a deleted method stays green forever. Only a check against the source
 * can fail for the right reason.
 *
 * Scope is deliberately limited to forms whose meaning is unambiguous. Excluded
 * on purpose:
 *
 *  - The `$var->method()` form. Its owning class is not machine-recoverable, and
 *    many hits are legitimately not ours: Carbon (`->addDays()`), Mockery, and
 *    the reader's own example code (`$this->persistTokens(...)`).
 *  - Bare class names in prose. `ApiException` and `EFacturaClient` appear
 *    hundreds of times inside @method docblocks and type columns; matching prose
 *    produces noise, not signal.
 *  - MCP topic keys. `InvoiceAddressData` and `CompanyAddressData` are tool
 *    aliases, not PHP classes — each topic carries a `**Namespace:**` line naming
 *    the real class, which is what we read instead.
 */
final class DocumentedSymbols
{
    /**
     * Symbols the docs name precisely BECAUSE they do not exist.
     *
     * The migration guide warns that a v2 facade alias pointed at a class which
     * never existed, and marks it `// ✗ class never existed`. Asserting it exists
     * would invert the guard and demand we create it.
     *
     * These are asserted ABSENT instead: if one is ever introduced, docs telling
     * users it never existed become a lie, and this fails.
     *
     * @var list<string>
     */
    private const DOCUMENTED_AS_ABSENT = [
        'BeeCoded\\EFacturaSdk\\Facades\\EFactura',
    ];

    /**
     * @return list<string>
     */
    public static function documentedAsAbsent(): array
    {
        return self::DOCUMENTED_AS_ABSENT;
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every documentation source, keyed by a human label for test failure output.
     *
     * @return array<string, string> label => markdown text
     */
    public static function sources(): array
    {
        $root = self::packageRoot();
        $sources = ['README.md' => (string) file_get_contents($root.'/README.md')];

        foreach (glob($root.'/mcp/src/content/*.ts') ?: [] as $path) {
            // The MCP content files are TypeScript template literals holding
            // markdown, so every backslash and backtick arrives escaped:
            // `BeeCoded\\EFacturaSdk\\Client` on disk is one backslash in the
            // rendered doc. Undo the escaping first — that also removes the
            // trailing `\` of an escaped closing backtick, which would otherwise
            // glue itself onto the symbol name.
            $label = 'mcp/src/content/'.basename($path);
            $sources[$label] = self::unescapeTemplateLiteral((string) file_get_contents($path));
        }

        return $sources;
    }

    /**
     * Resolve TypeScript template-literal escapes to the characters they denote.
     *
     * Processes left to right, so `\\` collapses to `\` and \` collapses to a
     * backtick without either interfering with the other.
     */
    public static function unescapeTemplateLiteral(string $text): string
    {
        return (string) preg_replace('/\\\\(.)/s', '$1', $text);
    }

    /**
     * Fully-qualified class names the docs claim, per source.
     *
     * @return array<string, list<string>> label => FQCNs
     */
    public static function fullyQualifiedClassNames(): array
    {
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\bBeeCoded\\\\EFacturaSdk\\\\[A-Za-z0-9_\\\\]+/', $text, $matches);

            // A trailing backslash means we matched a namespace written as
            // `BeeCoded\EFacturaSdk\Data\` — normalise it away.
            $names = array_map(fn (string $n): string => rtrim($n, '\\'), $matches[0]);
            $names = array_filter($names, fn (string $n): bool => ! in_array($n, self::DOCUMENTED_AS_ABSENT, true));

            $found[$label] = array_values(array_unique($names));
        }

        return $found;
    }

    /**
     * Every class, interface, trait and enum that really exists in this package.
     *
     * Built by walking src/ rather than by autoloading, so a documented symbol
     * that no longer has a file is a miss even if some stale alias resolves it.
     *
     * @return list<string>
     */
    public static function realSymbols(): array
    {
        $srcDir = self::packageRoot().'/src';
        $symbols = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($srcDir) + 1);
            $symbols[] = 'BeeCoded\\EFacturaSdk\\'.str_replace('/', '\\', substr($relative, 0, -4));
        }

        sort($symbols);

        return $symbols;
    }

    /**
     * True when $fqcn names a real symbol, or a namespace that really contains
     * symbols. Docs legitimately reference namespaces, which are not classes.
     *
     * @param  list<string>  $real
     */
    public static function resolves(string $fqcn, array $real): bool
    {
        if (in_array($fqcn, $real, true)) {
            return true;
        }

        foreach ($real as $symbol) {
            if (str_starts_with($symbol, $fqcn.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Short class name => FQCN, for names that are unambiguous.
     *
     * Ambiguous short names are dropped rather than guessed: this package
     * declares both Data\Invoice\AddressData and Data\Company\AddressData, and
     * both Facades\UblBuilder and Services\UblBuilder.
     *
     * @return array<string, string>
     */
    public static function unambiguousShortNames(): array
    {
        $byShort = [];

        foreach (self::realSymbols() as $fqcn) {
            $short = substr((string) strrchr($fqcn, '\\'), 1);
            $byShort[$short][] = $fqcn;
        }

        $unambiguous = [];
        foreach ($byShort as $short => $candidates) {
            if (count($candidates) === 1) {
                $unambiguous[$short] = $candidates[0];
            }
        }

        return $unambiguous;
    }

    /**
     * `Class::method(` references, resolved to the class they name.
     *
     * Only short names we can attribute are returned, which naturally drops
     * third-party (`Carbon::now()`, `Crypt::`, `Log::`) and placeholder
     * (`YourTokenModel::`, `EfacturaToken::` — the wrapper's model) classes.
     *
     * @return array<string, list<array{class: string, method: string, ref: string}>>
     */
    public static function staticMethodReferences(): array
    {
        $known = self::unambiguousShortNames();
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $text, $matches, PREG_SET_ORDER);

            $enums = self::enumsByShortName();

            $refs = [];
            foreach ($matches as [, $short, $method]) {
                if (! isset($known[$short])) {
                    continue;
                }

                // An enum case followed by a parenthetical is not a method call.
                if (isset($enums[$short]) && self::looksLikeEnumCase($method)) {
                    continue;
                }

                $refs[$short.'::'.$method] = [
                    'class' => $known[$short],
                    'method' => $method,
                    'ref' => $short.'::'.$method.'()',
                ];
            }

            $found[$label] = array_values($refs);
        }

        return $found;
    }

    /**
     * Does $method exist on $fqcn, accounting for how Laravel really resolves it?
     *
     * Three indirections matter, and all three appear in our docs:
     *  - Inheritance: method_exists already covers inherited and protected
     *    methods (docs deliberately describe protected BaseApiClient::call() for
     *    subclassers), plus enum builtins like from()/tryFrom() and spatie's
     *    Data::from()/validate().
     *  - Facades: `EFacturaSdkAuth::getAuthorizationUrl()` is declared only as an
     *    `@method static` docblock; it proxies to getFacadeAccessor()'s target.
     */
    public static function methodResolves(string $fqcn, string $method): bool
    {
        if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn)) {
            return false;
        }

        if (method_exists($fqcn, $method)) {
            return true;
        }

        $target = self::facadeTarget($fqcn);

        return $target !== null && method_exists($target, $method);
    }

    /**
     * The class or interface a facade proxies to, or null when $fqcn is not one.
     */
    public static function facadeTarget(string $fqcn): ?string
    {
        if (! is_subclass_of($fqcn, \Illuminate\Support\Facades\Facade::class)) {
            return null;
        }

        // getFacadeAccessor() is protected; since PHP 8.1 reflection can invoke
        // it without setAccessible(), which is now a deprecated no-op.
        $resolved = (new \ReflectionMethod($fqcn, 'getFacadeAccessor'))->invoke(null);

        if (! is_string($resolved)) {
            return null;
        }

        if (class_exists($resolved) || interface_exists($resolved)) {
            return $resolved;
        }

        // A container alias rather than a class name.
        return app()->bound($resolved) ? app($resolved)::class : null;
    }

    /**
     * Enum case references (`Enum::Case`), excluding static calls and `::class`.
     *
     * @return array<string, list<string>> label => "Enum::Case"
     */
    public static function enumCaseReferences(): array
    {
        $enums = self::enumsByShortName();
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, PREG_SET_ORDER);

            $refs = [];
            foreach ($matches as [$whole, $short, $member]) {
                if (! isset($enums[$short]) || ! self::looksLikeEnumCase($member)) {
                    continue;
                }
                $refs[] = $whole;
            }

            $found[$label] = array_values(array_unique($refs));
        }

        return $found;
    }

    /**
     * Is this member name an enum case rather than a static method?
     *
     * Convention decides it: cases are PascalCase (`ExecutionStatus::Success`),
     * methods are camelCase (`ExecutionStatus::from()`). Trailing parens cannot
     * decide it — docs write `` `UploadStatusValue::InProgress` (in progress) ``,
     * where the paren opens a parenthetical, not an argument list.
     */
    private static function looksLikeEnumCase(string $member): bool
    {
        return $member !== 'class' && preg_match('/^[A-Z]/', $member) === 1;
    }

    /**
     * Short enum name => FQCN, for enums this package declares.
     *
     * @return array<string, string>
     */
    public static function enumsByShortName(): array
    {
        $enums = [];

        foreach (glob(self::packageRoot().'/src/Enums/*.php') ?: [] as $path) {
            $short = basename($path, '.php');
            $enums[$short] = 'BeeCoded\\EFacturaSdk\\Enums\\'.$short;
        }

        return $enums;
    }

    /**
     * Config paths the docs claim, relative to config/efactura-sdk.php.
     *
     * Two forms are in use and both are handled:
     *  - Backticked markdown headings in config-reference.ts, written relative
     *    with no prefix: "### `oauth.client_id`". Only that file is scanned this
     *    way, since a heading elsewhere is not a config key.
     *  - Prefixed dotted paths in prose: "efactura-sdk.rate_limits.enabled".
     *    Anchored to real top-level groups so that `efactura-sdk.php` and
     *    `efactura-sdk.log` — filenames, not keys — do not masquerade as paths.
     *
     * @return array<string, list<string>> label => dotted path
     */
    public static function configPaths(): array
    {
        $groups = array_keys(self::realConfig());
        $groupAlternation = implode('|', array_map('preg_quote', $groups));
        $found = [];

        foreach (self::sources() as $label => $text) {
            $paths = [];

            if ($label === 'mcp/src/content/config-reference.ts') {
                preg_match_all('/^#{1,4}\s+`([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)`/m', $text, $m);
                $paths = array_merge($paths, $m[1]);
            }

            if ($groupAlternation !== '') {
                preg_match_all("/\befactura-sdk\.(({$groupAlternation})(?:\.[a-z0-9_]+)*)\b/", $text, $m);
                $paths = array_merge($paths, $m[1]);
            }

            $found[$label] = array_values(array_unique($paths));
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    public static function realConfig(): array
    {
        return require self::packageRoot().'/config/efactura-sdk.php';
    }

    /**
     * EFACTURA_* env vars the docs claim.
     *
     * @return array<string, list<string>> label => env var name
     */
    public static function envVarReferences(): array
    {
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\bEFACTURA_[A-Z0-9_]+\b/', $text, $matches);
            $found[$label] = array_values(array_unique($matches[0]));
        }

        return $found;
    }

    /**
     * EFACTURA_* env vars actually read by config/efactura-sdk.php.
     *
     * @return list<string>
     */
    public static function realEnvVars(): array
    {
        $path = self::packageRoot().'/config/efactura-sdk.php';
        preg_match_all("/env\(\s*['\"](EFACTURA_[A-Z0-9_]+)['\"]/", (string) file_get_contents($path), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * DTO members documented in the "Constructor Parameters" tables of
     * dto-structures.ts, attributed to the class each topic names.
     *
     * Topics are keyed by MCP alias (InvoiceAddressData), not by class, so the
     * owning class is read from the topic's own `**Namespace:**` line.
     *
     * @return list<array{class: string, member: string, ref: string}>
     */
    public static function dtoMembers(): array
    {
        $text = self::sources()['mcp/src/content/dto-structures.ts'] ?? '';
        if ($text === '') {
            return [];
        }

        // Both "**Namespace:**" and the alias topics' "**Full namespace:**" mark
        // the start of a block; everything up to the next one belongs to it.
        preg_match_all(
            '/\*\*(?:Full namespace|Namespace):\*\*\s+`([A-Za-z0-9_\\\\]+)`/',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $members = [];
        $blocks = $matches[1];

        foreach ($blocks as $i => [$fqcn, $offset]) {
            $end = $blocks[$i + 1][1] ?? strlen($text);
            $block = substr($text, $offset, $end - $offset);

            // Table rows only: "| `$invoiceNumber` | `string` | yes | ...".
            // Anchoring to the row start keeps prose mentions of `$lines` out.
            preg_match_all('/^\|\s*`\$([A-Za-z_][A-Za-z0-9_]*)`/m', $block, $rows);

            foreach (array_unique($rows[1]) as $member) {
                $members[] = [
                    'class' => $fqcn,
                    'member' => $member,
                    'ref' => substr((string) strrchr($fqcn, '\\'), 1).'::$'.$member,
                ];
            }
        }

        return $members;
    }

    /**
     * Does $name exist on $fqcn as a property or a constructor parameter?
     *
     * The documented tables are headed "Constructor Parameters", and not every
     * parameter becomes a property — OAuthTokensData takes $expiresIn purely to
     * derive $expiresAt. Checking both is what the docs actually claim.
     */
    public static function memberExists(string $fqcn, string $name): bool
    {
        if (! class_exists($fqcn)) {
            return false;
        }

        if (property_exists($fqcn, $name)) {
            return true;
        }

        $constructor = (new ReflectionClass($fqcn))->getConstructor();

        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === $name) {
                return true;
            }
        }

        return false;
    }
}

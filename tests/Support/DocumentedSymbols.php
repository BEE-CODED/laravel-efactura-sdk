<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Tests\Support;

use Illuminate\Support\Facades\Facade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Extracts the symbols our documentation claims exist, so DocsDriftTest can
 * check each one against PHP reality.
 *
 * Why this exists: a test that asserts documentation *contains* a string is
 * circular. The docs are both the subject and the oracle, so a doc naming a
 * deleted method stays green forever. Only a check against the source can fail
 * for the right reason.
 *
 * Scope is deliberately limited to forms whose meaning is unambiguous. Excluded
 * on purpose:
 *
 *  - The `$var->method()` form. Its owning class is not machine-recoverable, and
 *    many hits are legitimately not ours: Carbon (`->addDays()`), Mockery, and
 *    the reader's own example code (`$this->persistTokens(...)`).
 *  - Bare class names in prose. `ApiException` and `EFacturaClient` appear
 *    hundreds of times in examples and tables; matching prose produces noise,
 *    not signal.
 */
final class DocumentedSymbols
{
    /** Regex for this package's namespace prefix, with its trailing backslash. */
    private const OWN_NAMESPACE = 'BeeCoded\\\\EFacturaSdk\\\\(?:\w+\\\\)*';

    /**
     * Symbols that 4.0 removed. CHANGELOG.md names them to tell users they are
     * gone, so a miss there is expected. Anywhere else a miss is still a miss.
     *
     * Entries are flat strings per kind: 'Fqcn::method', 'Fqcn', 'Fqcn::CONST',
     * a config path relative to efactura-sdk ('rate_limits.enabled') and an env
     * var ('EFACTURA_X'). Add an entry in the same change that removes the symbol.
     */
    private const DOCUMENTED_AS_REMOVED = [
        'method' => [
            'BeeCoded\\EFacturaSdk\\Services\\ApiClients\\RateLimitStore::clear',
            'BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData::getRawLineTotal',
        ],
        'class' => [],
        'constant' => [],
        'config' => [],
        'env' => [],
    ];

    /**
     * @return array<string, list<string>> kind => symbols
     */
    public static function documentedAsRemoved(): array
    {
        return self::DOCUMENTED_AS_REMOVED;
    }

    public static function isDocumentedAsRemoved(string $label, string $kind, string $symbol): bool
    {
        return $label === 'CHANGELOG.md' && in_array($symbol, self::DOCUMENTED_AS_REMOVED[$kind], true);
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

        return [
            'README.md' => (string) file_get_contents($root.'/README.md'),
            'CHANGELOG.md' => (string) file_get_contents($root.'/CHANGELOG.md'),
        ];
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
            // Our own namespace-qualified names are checked by their full name. The
            // lookbehind skips other qualified names such as
            // `Illuminate\Support\Facades\RateLimiter::clear()`, which are not ours.
            preg_match_all('/(?:\b('.self::OWN_NAMESPACE.')|(?<![\\\\\w]))([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $text, $matches, PREG_SET_ORDER);

            $refs = [];
            foreach ($matches as [, $namespace, $short, $method]) {
                $class = $namespace !== '' ? $namespace.$short : ($known[$short] ?? null);

                // An enum case followed by a parenthetical is not a method call.
                if ($class === null || (enum_exists($class) && self::looksLikeEnumCase($method))) {
                    continue;
                }

                $refs[$class.'::'.$method] = [
                    'class' => $class,
                    'method' => $method,
                    'ref' => $namespace.$short.'::'.$method.'()',
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
        if (! is_subclass_of($fqcn, Facade::class)) {
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
     * Class constant references (`Class::NAME`) on classes that are not enums.
     *
     * Only unambiguous short names are kept, and the lookbehind skips
     * namespace-qualified third-party names, as for static methods; our own
     * qualified names are checked by their full name.
     *
     * @return array<string, list<array{class: string, constant: string, ref: string}>>
     */
    public static function constantReferences(): array
    {
        $known = array_diff_key(self::unambiguousShortNames(), self::enumsByShortName());
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/(?:\b('.self::OWN_NAMESPACE.')|(?<![\\\\\w]))([A-Z][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]+)\b/', $text, $matches, PREG_SET_ORDER);

            $refs = [];
            foreach ($matches as [$whole, $namespace, $short, $constant]) {
                $class = $namespace !== '' ? $namespace.$short : ($known[$short] ?? null);

                if ($class !== null && ! enum_exists($class)) {
                    $refs[$whole] = ['class' => $class, 'constant' => $constant, 'ref' => $whole];
                }
            }

            $found[$label] = array_values($refs);
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
     * Prefixed dotted paths in prose: "efactura-sdk.rate_limits.enabled".
     * Anchored to real top-level groups so that `efactura-sdk.php` and
     * `efactura-sdk.log`, filenames rather than keys, do not masquerade as
     * paths. Removed config groups join the alternation, so CHANGELOG.md can
     * name them.
     *
     * @return array<string, list<string>> label => dotted path
     */
    public static function configPaths(): array
    {
        $removedGroups = array_map(
            fn (string $path): string => explode('.', $path)[0],
            self::DOCUMENTED_AS_REMOVED['config'],
        );
        $groups = array_values(array_unique([...array_keys(self::realConfig()), ...$removedGroups]));
        $groupAlternation = implode('|', array_map('preg_quote', $groups));
        $found = [];

        foreach (self::sources() as $label => $text) {
            $paths = [];

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
}

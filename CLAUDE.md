# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel SDK package for integrating with Romania's ANAF e-Factura system (electronic invoicing).

## Development Guidelines

### Package Structure (Laravel Package Convention)
```
src/
├── EFacturaServiceProvider.php  # Laravel service provider
├── Facades/                     # Laravel facades
├── Services/                    # Business logic (API clients, XML builders)
├── Models/                      # Data transfer objects / Eloquent models
├── Exceptions/                  # Custom exceptions
└── Contracts/                   # Interfaces
config/
    efactura-sdk.php             # Package configuration
tests/
    Feature/
    Unit/
```

### Commands

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Run single test
./vendor/bin/phpunit --filter TestMethodName

# Run tests with coverage
./vendor/bin/phpunit --coverage-html coverage

# Code style (if using Laravel Pint)
./vendor/bin/pint

# Static analysis (if using PHPStan)
./vendor/bin/phpstan analyse
```

### Code Conventions

- Use `->foreignIdFor()` instead of `->foreignId()` in migrations when referencing model classes
- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`

### Architecture Patterns

#### Business Logic Separation
- **Services**: ALL business logic belongs in Service classes
- **Models**: ONLY relationships, scopes, accessors, and simple state checks like `isValid()`, `isCompleted()`
- ❌ NEVER put API calls, data transformations, or complex logic in Models

#### Dependency Injection
Services ALWAYS use constructor injection:
```php
class EFacturaClient
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly AnafAuthenticator $authenticator,
    ) {}
}
```

#### Service Pattern
```php
// ✅ Correct - business logic in service
class TokenRepository
{
    public function getTokensForCif(string $cif): ?OAuthTokensData
    {
        $token = EFacturaToken::where('cif', $cif)->first();
        if (!$token) return null;

        return new OAuthTokensData(
            accessToken: Crypt::decryptString($token->access_token),
            refreshToken: Crypt::decryptString($token->refresh_token),
            expiresAt: $token->expires_at,
        );
    }
}

// ❌ Wrong - business logic in model
class EFacturaToken extends Model
{
    public function getDecryptedTokens(): OAuthTokensData { /* NO */ }
}
```

#### Model Pattern
```php
class EFacturaToken extends Model
{
    use HasFactory;

    protected $fillable = ['cif', 'access_token', 'refresh_token', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    // ✅ Simple state check - OK in model
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    // ❌ Business logic - belongs in service
    public function refresh(): void { /* NO - put in TokenRepository */ }
}
```

#### API Client Pattern
All API clients extend `BaseApiClient`:
```php
class EFacturaClient extends BaseApiClient
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly AnafAuthenticator $authenticator,
    ) {
        parent::__construct();
    }

    public static function getBaseUrl(): string
    {
        return config('efactura.sandbox')
            ? 'https://api.anaf.ro/test/FCTEL/rest'
            : 'https://api.anaf.ro/prod/FCTEL/rest';
    }

    public static function getTimeoutDuration(): float|int
    {
        return config('efactura.http.timeout', 30);
    }

    public static function getLogger(): LoggerInterface
    {
        return Log::channel(config('efactura.logging.channel', 'efactura'));
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/xml',
            'Accept' => 'application/json',
        ];
    }
}
```

#### Data Transfer Objects (DTOs)
Use `spatie/laravel-data` for all DTOs:
```php
use Spatie\LaravelData\Data;

class InvoiceData extends Data
{
    public function __construct(
        public string $invoiceNumber,
        public Carbon|string $issueDate,
        public PartyData $supplier,
        public PartyData $customer,
        /** @var InvoiceLineData[] */
        public array $lines,
    ) {}
}
```

#### Enums
Use PHP 8.1+ backed enums:
```php
enum MessageFilter: string
{
    case InvoiceSent = 'T';
    case InvoiceReceived = 'P';
    case InvoiceErrors = 'E';
    case BuyerMessage = 'R';
}
```

#### Exception Pattern
```php
class EFacturaException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = []
    ) {
        parent::__construct($message, $code, $previous);
    }
}

class AuthenticationException extends EFacturaException {}
class ValidationException extends EFacturaException {}
class ApiException extends EFacturaException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
```

### e-Factura API Context

The package interacts with ANAF's e-Factura system which:
- Uses OAuth2 for authentication
- Accepts UBL 2.1 XML format invoices
- Has separate endpoints for test/production environments
- Requires digital certificates for production

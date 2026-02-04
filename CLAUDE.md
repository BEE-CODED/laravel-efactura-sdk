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
    efactura.php                 # Package configuration
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
- Business logic belongs in Service classes, not Models (keep Models for relationships, scopes, accessors, and simple state checks like `isValid()`, `isCompleted()`)
- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`

### e-Factura API Context

The package interacts with ANAF's e-Factura system which:
- Uses OAuth2 for authentication
- Accepts UBL 2.1 XML format invoices
- Has separate endpoints for test/production environments
- Requires digital certificates for production

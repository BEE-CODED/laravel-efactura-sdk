# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel SDK package for integrating with Romania's ANAF e-Factura system (electronic invoicing).

## Development Guidelines

### Commands

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/pest

# Run single test
vendor/bin/pest --filter "test name"

# Run tests with coverage
vendor/bin/pest --coverage-html coverage

# Code style (if using Laravel Pint)
./vendor/bin/pint

# Static analysis (if using PHPStan)
./vendor/bin/phpstan analyse
```

### Code Conventions

- Use `->foreignIdFor()` instead of `->foreignId()` in migrations when referencing model classes
- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`
- Services use constructor injection (`private readonly`).

### Business Logic Separation
- **Services**: ALL business logic belongs in Service classes
- **Models**: ONLY relationships, scopes, accessors, and simple state checks like `isValid()`, `isCompleted()`
- ❌ NEVER put API calls, data transformations, or complex logic in Models

### Workflow Rules

- **No auto-commit**: Do NOT run `git add` or `git commit` unless explicitly asked. The user decides when to commit.
- **No Co-Authored-By**: Do NOT add `Co-Authored-By` lines to commit messages.

### e-Factura API Context

The package interacts with ANAF's e-Factura system which:
- Uses OAuth2 for authentication
- Accepts UBL 2.1 XML format invoices
- Has separate endpoints for test/production environments
- Requires digital certificates for production

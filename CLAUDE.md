# CLAUDE.md

## Package Overview

`centrex/laravel-btyd` — Buy-Til-You-Die (BG/NBD + Gamma-Gamma) customer lifetime value (CLV) prediction for Laravel.

Namespace: `Centrex\Btyd\`  
Service Provider: `BtydServiceProvider`  
Facade: `Facades/Btyd`

## Commands

Run from inside this directory (`cd laravel-btyd`):

```sh
composer install          # install dependencies
composer test             # full suite: rector dry-run, pint check, phpstan, pest
composer test:unit        # pest tests only
composer test:lint        # pint style check (read-only)
composer test:types       # phpstan static analysis
composer test:refacto     # rector refactor check (read-only)
composer lint             # apply pint formatting
composer refacto          # apply rector refactors
composer analyse          # phpstan (alias)
composer build            # prepare testbench workbench
composer start            # build + serve testbench dev server
```

Run a single test:
```sh
vendor/bin/pest tests/ExampleTest.php
vendor/bin/pest --filter "test name"
```

## Structure

```
src/
  Btyd.php                        # Main CLV prediction class
  BtydServiceProvider.php
  Facades/
  Commands/
  Models/
  NelderMeadOptimizer.php         # Numerical optimizer for BG/NBD parameter fitting
config/config.php
database/migrations/
tests/
workbench/
```

## Key Concepts

- **BG/NBD model**: Predicts future purchase frequency for a customer
- **Gamma-Gamma model**: Predicts expected average transaction value
- **NelderMeadOptimizer**: Numerical optimization used to fit model parameters from transaction history
- Input: recency, frequency, T (customer age), monetary value
- Output: predicted CLV over a future period

## Conventions

- PHP 8.2+, `declare(strict_types=1)` in all files
- Pest for tests, snake_case test names
- Pint with `laravel` preset
- Rector targeting PHP 8.3 with `CODE_QUALITY`, `DEAD_CODE`, `EARLY_RETURN`, `TYPE_DECLARATION`, `PRIVATIZATION` sets
- PHPStan at level `max` with Larastan

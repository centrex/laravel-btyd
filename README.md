# Laravel package for BTYD (BG/NBD + Gamma-Gamma) CLV prediction

[![Latest Version on Packagist](https://img.shields.io/packagist/v/centrex/btyd.svg?style=flat-square)](https://packagist.org/packages/centrex/btyd)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/centrex/btyd/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/centrex/btyd/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/centrex/btyd/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/centrex/btyd/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/centrex/btyd?style=flat-square)](https://packagist.org/packages/centrex/btyd)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Contents

  - [Installation](#installation)
  - [Usage](#usage)
  - [Testing](#testing)
  - [Changelog](#changelog)
  - [Contributing](#contributing)
  - [Credits](#credits)
  - [License](#license)

## Installation

You can install the package via composer:

```bash
composer require centrex/btyd
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="btyd-config"
```

This is the contents of the published config file:

```php
return [
];
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="btyd-migrations"
php artisan migrate
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="btyd-views"
```

## Usage

```php
$btyd = new Centrex\Btyd();
echo $btyd->echoPhrase('Hello, Centrex!');
```

## Testing

🧹 Keep a modern codebase with **Pint**:
```bash
composer lint
```

✅ Run refactors using **Rector**
```bash
composer refacto
```

⚗️ Run static analysis using **PHPStan**:
```bash
composer test:types
```

✅ Run unit tests using **PEST**
```bash
composer test:unit
```

🚀 Run the entire test suite:
```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [rochi88](https://github.com/centrex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

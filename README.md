# Vendor Kill

[![Latest Version on Packagist](https://img.shields.io/packagist/v/abdelhamiderrahmouni/vendorkill.svg?style=flat-square)](https://packagist.org/packages/abdelhamiderrahmouni/vendorkill)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/abdelhamiderrahmouni/vendorkill/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/abdelhamiderrahmouni/vendorkill/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/abdelhamiderrahmouni/vendorkill/pint.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/abdelhamiderrahmouni/vendorkill/actions?query=workflow%3A"pint"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/abdelhamiderrahmouni/vendorkill.svg?style=flat-square)](https://packagist.org/packages/abdelhamiderrahmouni/vendorkill)

a composer package to install globally and remove composer vendor folders in you old project to save storage.

## Installation

You can install the package globally via composer:

```bash
composer global require abdelhamiderrahmouni/vendorkill
```

you will find it installed in `~/.composer/vendor/bin` or `~/.config/composer/vendor/bin` directory.
add it to your path to use it globally or create an alias like the following:

```bash
alias vendorkill="~/.composer/vendor/bin/vendorkill"
# or
alias vendorkill="~/.config/composer/vendor/bin/vendorkill"
```


## Usage

```bash
vendorkill [path: defaults to current path] [options: --maxdepth=2 --full]
```

```bash
## Examples

vendorkill # remove vendor folders in current path

vendorkill /path/to/project # remove vendor folders in /path/to/project

vendorkill /path/to/project --maxdepth=4 # remove vendor folders in /path/to/project with maxdepth=4

vendorkill /path/to/project --full # remove vendor folders in /path/to/project and all subdirectories
```

## Update

```bash

```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Abdelhamid Errahmouni](https://github.com/abdelhamiderrahmouni)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

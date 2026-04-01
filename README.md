
<pre style="color: #3388ff;">
   █████████  ██████   █████ █████   ████ █████ █████       █████      
  ███░░░░░██  ░██████  ░███  ░███   ███░  ░███  ░███        ░███       
 ███     ░░░  ░███░███ ░███  ░███  ███    ░███  ░███        ░███       
░███          ░███░░███░███  ░███████     ░███  ░███        ░███       
░███          ░███ ░░██████  ░███░░███    ░███  ░███        ░███       
░░███     ███ ░███  ░░█████  ░███ ░░███   ░███  ░███      █ ░███      █
 ░░█████████  █████  ░░█████ █████ ░░████ █████ ███████████ ███████████
  ░░░░░░░░░  ░░░░░    ░░░░░ ░░░░░   ░░░░ ░░░░░ ░░░░░░░░░░░ ░░░░░░░░░░░
</pre>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/abdelhamiderrahmouni/cnkill.svg?style=flat-square)](https://packagist.org/packages/abdelhamiderrahmouni/cnkill)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/abdelhamiderrahmouni/cnkill/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/abdelhamiderrahmouni/cnkill/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/abdelhamiderrahmouni/cnkill/pint.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/abdelhamiderrahmouni/cnkill/actions?query=workflow%3A"pint"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/abdelhamiderrahmouni/cnkill.svg?style=flat-square)](https://packagist.org/packages/abdelhamiderrahmouni/cnkill)

A Blazing Fast, Interactive TUI tool (like `npkill`) to find and delete `vendor/` and `node_modules/` directories in your old projects — freeing up disk space fast.

## Installation && Update
We provide multiple installation methods to suit your preferences. The recommended way is the standalone executable for Linux and macOS.

1. Quick install (Linux/macOS, standalone executable):

```bash
curl -fsSL https://raw.githubusercontent.com/abdelhamiderrahmouni/cnkill/master/install.sh | sh
```

2. System-wide install (requires sudo):

```bash
curl -fsSL https://raw.githubusercontent.com/abdelhamiderrahmouni/cnkill/master/install.sh | sh -s -- --system
```

3. Install via cpx (the npx for PHP):

```bash
cpx abdelhamiderrahmouni/cnkill
```

4. Install globally via Composer:

```bash
composer global require abdelhamiderrahmouni/cnkill
```

You will find it installed in `~/.composer/vendor/bin` or `~/.config/composer/vendor/bin`.
Add it to your PATH or create an alias:

```bash
alias cnkill="~/.composer/vendor/bin/cnkill"
# or
alias cnkill="~/.config/composer/vendor/bin/cnkill"
```

## Usage

```bash
cnkill [path: defaults to current path] [options]
```

```bash
## Examples

cnkill                          # find vendor/ in current path

cnkill /path/to/projects        # find vendor/ in a specific path

cnkill /path/to/projects --node # find node_modules/ only

cnkill /path/to/projects --all  # find both vendor/ and node_modules/

cnkill /path/to/projects --sort=size  # sort by size

cnkill /path/to/projects --maxdepth=4  # limit search depth

cnkill cache --sort=modified  # sort caches by last modified
```

## Controls

| Key | Action |
|-----|--------|
| `↑` / `↓` | Navigate the list |
| `←` / `→` | Page through the list |
| `s` | Cycle sort mode |
| `Shift + s` | Toggle sort direction |
| `Space` | Delete the highlighted directory |
| `q` / `Ctrl-C` | Quit |

## Roadmap
- [x] Interactive TUI with real-time streaming results
- [x] Support for `vendor/` directories (Composer)
- [x] Support for `node_modules/` directories (npm/yarn)
- [x] Async size calculation and deletion
- [ ] Add support for Windows
- [x] Add a build workflow to publish release binaries automatically
- ... share your ideas in the [issues](https://github.com/abdelhamiderrahmouni/cnkill/issues)

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](/.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please open an [issue](https://github.com/abdelhamiderrahmouni/cnkill/issues) to report any security vulnerabilities.

## Credits

- [Abdelhamid Errahmouni](https://github.com/abdelhamiderrahmouni)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

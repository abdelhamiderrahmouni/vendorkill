<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Manages persistent user configuration for cnkill.
 *
 * Config is stored at ~/.config/cnkill/config.json (respecting XDG_CONFIG_HOME).
 * The primary setting is which folder types to scan.
 */
class ConfigService
{
    /**
     * The complete list of all known folder types, their display names, default
     * enabled state, and the directory names / path patterns that `find` should look for.
     *
     * 'names'  — simple directory names matched with -name (single segment)
     * 'paths'  — path patterns matched with -path (multi-segment, e.g. android/build)
     *
     * @var array<string, array{label: string, default: bool, names: string[], paths: string[]}>
     */
    public const FOLDER_TYPES = [
        'vendor' => [
            'label' => 'vendor  (Composer)',
            'default' => true,
            'names' => ['vendor'],
            'paths' => [],
        ],
        'node' => [
            'label' => 'node_modules  (npm / pnpm / yarn / bun)',
            'default' => true,
            'names' => ['node_modules'],
            'paths' => [],
        ],
        'next' => [
            'label' => '.next  (Next.js build output)',
            'default' => true,
            'names' => ['.next'],
            'paths' => [],
        ],
        'expo' => [
            'label' => '.expo  (Expo / React Native)',
            'default' => true,
            'names' => ['.expo'],
            'paths' => [],
        ],
        'turbo' => [
            'label' => '.turbo  (Turborepo cache)',
            'default' => true,
            'names' => ['.turbo'],
            'paths' => [],
        ],
        'svelte-kit' => [
            'label' => '.svelte-kit  (SvelteKit build output)',
            'default' => true,
            'names' => ['.svelte-kit'],
            'paths' => [],
        ],
        'nuxt' => [
            'label' => '.nuxt  (Nuxt build output)',
            'default' => true,
            'names' => ['.nuxt'],
            'paths' => [],
        ],
        'cache' => [
            'label' => '.cache  (generic tool cache)',
            'default' => true,
            'names' => ['.cache'],
            'paths' => [],
        ],
        'parcel-cache' => [
            'label' => '.parcel-cache  (Parcel bundler cache)',
            'default' => true,
            'names' => ['.parcel-cache'],
            'paths' => [],
        ],
        'coverage' => [
            'label' => 'coverage  (test coverage reports)',
            'default' => true,
            'names' => ['coverage'],
            'paths' => [],
        ],
        'output' => [
            'label' => '.output  (Nitro / Nuxt server output)',
            'default' => true,
            'names' => ['.output'],
            'paths' => [],
        ],
        'dist' => [
            'label' => 'dist  (build distribution — may have false positives)',
            'default' => false,
            'names' => ['dist'],
            'paths' => [],
        ],
        'build' => [
            'label' => 'build  (generic build output — may have false positives)',
            'default' => false,
            'names' => ['build'],
            'paths' => [],
        ],
        'derived-data' => [
            'label' => 'DerivedData  (Xcode)',
            'default' => false,
            'names' => ['DerivedData'],
            'paths' => [],
        ],
        'android' => [
            'label' => 'android/build  (Android / Gradle)',
            'default' => false,
            'names' => [],
            'paths' => ['*/android/build'],
        ],
    ];

    /**
     * The manifest files that indicate a real project directory.
     * A discovered folder is only registered if one of these exists in the parent.
     *
     * @var string[]
     */
    public const MANIFESTS = [
        'package.json',
        'composer.json',
        'Cargo.toml',
        'pyproject.toml',
        'go.mod',
        'build.gradle',
        'build.gradle.kts',
    ];

    /**
     * Resolve the path to the config file.
     */
    public function configPath(): string
    {
        $xdgConfig = rtrim((string) ($_SERVER['XDG_CONFIG_HOME'] ?? ''), DIRECTORY_SEPARATOR);

        if ($xdgConfig === '') {
            $home = rtrim((string) ($_SERVER['HOME'] ?? ''), DIRECTORY_SEPARATOR);
            $xdgConfig = $home . '/.config';
        }

        return $xdgConfig . '/cnkill/config.json';
    }

    /**
     * Read the config file and return the decoded array, or [] on failure.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $path = $this->configPath();

        if (! file_exists($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write the config array to disk, creating parent directories as needed.
     *
     * @param  array<string, mixed>  $config
     */
    public function write(array $config): bool
    {
        $path = $this->configPath();
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        return file_put_contents($path, $json) !== false;
    }

    /**
     * Return the list of enabled folder type keys, taking defaults into account
     * when no config file exists yet.
     *
     * @return string[]
     */
    public function getEnabledTypes(): array
    {
        $config = $this->read();

        if (! isset($config['enabled_types'])) {
            // No saved config — return the types that are on by default.
            return array_keys(array_filter(
                self::FOLDER_TYPES,
                fn (array $t) => $t['default']
            ));
        }

        // Intersect with the known list so stale/unknown keys are silently dropped.
        return array_values(array_intersect(
            (array) $config['enabled_types'],
            array_keys(self::FOLDER_TYPES)
        ));
    }

    /**
     * Persist the enabled type keys to config.
     *
     * @param  string[]  $types
     */
    public function setEnabledTypes(array $types): bool
    {
        $config = $this->read();
        $config['enabled_types'] = array_values(array_intersect(
            $types,
            array_keys(self::FOLDER_TYPES)
        ));

        return $this->write($config);
    }
}

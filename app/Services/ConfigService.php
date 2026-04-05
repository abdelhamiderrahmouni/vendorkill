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
     * The complete list of all known folder types.
     *
     * Keys:
     *   'label'      — human-readable name shown in `cnkill config`
     *   'default'    — whether the type is enabled when no config file exists
     *   'names'      — simple directory names matched with `find -name` (single segment)
     *   'paths'      — path patterns matched with `find -path` (multi-segment, e.g. android/build)
     *   'manifests'  — files that must exist in the parent directory to confirm a real project;
     *                  for the 'android' type these are checked in both the immediate parent
     *                  (<project>/android/) and one level up (<project>/).
     *   'lockfiles'  — additional files used to resolve the best last-modified timestamp to display
     *
     * @var array<string, array{label: string, default: bool, names: string[], paths: string[], manifests: string[], lockfiles: string[]}>
     */
    public const FOLDER_TYPES = [
        'vendor' => [
            'label' => 'vendor  (Composer)',
            'default' => true,
            'names' => ['vendor'],
            'paths' => [],
            'manifests' => ['composer.json'],
            'lockfiles' => ['composer.json', 'composer.lock'],
        ],
        'node' => [
            'label' => 'node_modules  (npm / pnpm / yarn / bun)',
            'default' => true,
            'names' => ['node_modules'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'next' => [
            'label' => '.next  (Next.js build output)',
            'default' => true,
            'names' => ['.next'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'expo' => [
            'label' => '.expo  (Expo / React Native)',
            'default' => true,
            'names' => ['.expo'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'turbo' => [
            'label' => '.turbo  (Turborepo cache)',
            'default' => true,
            'names' => ['.turbo'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'svelte-kit' => [
            'label' => '.svelte-kit  (SvelteKit build output)',
            'default' => true,
            'names' => ['.svelte-kit'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'nuxt' => [
            'label' => '.nuxt  (Nuxt build output)',
            'default' => true,
            'names' => ['.nuxt'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'cache' => [
            'label' => '.cache  (generic tool cache)',
            'default' => true,
            'names' => ['.cache'],
            'paths' => [],
            'manifests' => ['package.json', 'composer.json', 'Cargo.toml', 'pyproject.toml', 'go.mod'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'parcel-cache' => [
            'label' => '.parcel-cache  (Parcel bundler cache)',
            'default' => true,
            'names' => ['.parcel-cache'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'coverage' => [
            'label' => 'coverage  (test coverage reports)',
            'default' => true,
            'names' => ['coverage'],
            'paths' => [],
            'manifests' => ['package.json', 'composer.json', 'pyproject.toml', 'go.mod'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', 'composer.json', 'composer.lock'],
        ],
        'output' => [
            'label' => '.output  (Nitro / Nuxt server output)',
            'default' => true,
            'names' => ['.output'],
            'paths' => [],
            'manifests' => ['package.json'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'],
        ],
        'dist' => [
            'label' => 'dist  (build distribution — may have false positives)',
            'default' => false,
            'names' => ['dist'],
            'paths' => [],
            'manifests' => ['package.json', 'composer.json', 'Cargo.toml', 'pyproject.toml'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', 'composer.json', 'composer.lock'],
        ],
        'build' => [
            'label' => 'build  (generic build output — may have false positives)',
            'default' => false,
            'names' => ['build'],
            'paths' => [],
            'manifests' => ['package.json', 'composer.json', 'Cargo.toml', 'pyproject.toml', 'go.mod', 'build.gradle', 'build.gradle.kts'],
            'lockfiles' => ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', 'composer.json', 'composer.lock'],
        ],
        'derived-data' => [
            'label' => 'DerivedData  (Xcode)',
            'default' => false,
            'names' => ['DerivedData'],
            'paths' => [],
            'manifests' => ['Package.swift', 'project.pbxproj'],
            'lockfiles' => ['Package.swift', 'Package.resolved'],
        ],
        'android' => [
            'label' => 'android/build  (Android / Gradle)',
            'default' => false,
            'names' => [],
            'paths' => ['*/android/build'],
            // Checked in both the immediate parent (<project>/android/) and project root
            'manifests' => ['build.gradle', 'build.gradle.kts', 'settings.gradle', 'settings.gradle.kts', 'package.json', 'pubspec.yaml'],
            'lockfiles' => ['build.gradle', 'build.gradle.kts', 'package.json', 'pubspec.yaml'],
        ],
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

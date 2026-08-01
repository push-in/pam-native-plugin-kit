<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

use JsonException;
use Pam\Native\PluginKit\Ios\IosExtensionKind;
use Pam\Native\PluginKit\Ios\SwiftPackageRequirementKind;

final class ManifestValidator
{
    private const int MAX_BYTES = 1_048_576;

    public function validateFile(string $path): ValidationResult
    {
        if (!is_file($path)) {
            return $this->failure('$', "Manifest does not exist: {$path}");
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            return $this->failure('$', 'Manifest exceeds the one MiB safety limit.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return $this->failure('$', 'Manifest cannot be read.');
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->failure('$', 'Invalid JSON: '.$exception->getMessage());
        }

        if (!is_array($manifest) || array_is_list($manifest)) {
            return $this->failure('$', 'Manifest must be a JSON object.');
        }

        return $this->validate($manifest, dirname(realpath($path) ?: $path));
    }

    /** @param array<string, mixed> $manifest */
    public function validate(array $manifest, string $packageRoot): ValidationResult
    {
        $diagnostics = [];
        $allowed = ['$schema', 'version', 'protocol', 'pamNative', 'php', 'android', 'ios', 'modules', 'views', 'idl'];
        foreach (array_keys($manifest) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $diagnostics[] = $this->error('$.'.(string) $key, 'Unknown manifest property.');
            }
        }

        if (($manifest['version'] ?? null) !== 1) {
            $diagnostics[] = $this->error('$.version', 'Manifest version must be integer 1.');
        }
        if (($manifest['protocol'] ?? null) !== 1) {
            $diagnostics[] = $this->error('$.protocol', 'Protocol must be integer 1.');
        }

        $compatibility = $manifest['pamNative'] ?? null;
        if (!is_array($compatibility) || array_is_list($compatibility)) {
            $diagnostics[] = $this->error('$.pamNative', 'pamNative must be an object.');
        } else {
            $minimum = $compatibility['minimum'] ?? null;
            $maximum = $compatibility['maximumExclusive'] ?? null;
            if (!is_string($minimum) || !$this->semanticVersion($minimum)) {
                $diagnostics[] = $this->error('$.pamNative.minimum', 'Expected a semantic version.');
            }
            if (!is_string($maximum) || !$this->semanticVersion($maximum)) {
                $diagnostics[] = $this->error('$.pamNative.maximumExclusive', 'Expected a semantic version.');
            }
            if (is_string($minimum) && is_string($maximum) && $this->semanticVersion($minimum)
                && $this->semanticVersion($maximum) && version_compare($minimum, $maximum, '>=')) {
                $diagnostics[] = $this->error('$.pamNative', 'minimum must be lower than maximumExclusive.');
            }
        }

        $bindingNames = [];
        foreach (['modules', 'views'] as $collection) {
            $bindings = $manifest[$collection] ?? [];
            if (!is_array($bindings) || !array_is_list($bindings)) {
                $diagnostics[] = $this->error('$.'.$collection, 'Expected a list.');
                continue;
            }
            foreach ($bindings as $index => $binding) {
                $path = '$.'.$collection.'['.$index.']';
                if (!is_array($binding) || array_is_list($binding)) {
                    $diagnostics[] = $this->error($path, 'Binding must be an object.');
                    continue;
                }
                $name = $binding['name'] ?? null;
                if (!is_string($name) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $name) !== 1) {
                    $diagnostics[] = $this->error($path.'.name', 'Invalid native binding name.');
                } elseif (isset($bindingNames[$name])) {
                    $diagnostics[] = $this->error($path.'.name', 'Native binding name is duplicated.');
                } else {
                    $bindingNames[$name] = true;
                }
                $class = $binding['class'] ?? null;
                if (!is_string($class) || !$this->nativeClass($class, true)) {
                    $diagnostics[] = $this->error($path.'.class', 'Invalid Kotlin class name.');
                }
                $iosClass = $binding['iosClass'] ?? null;
                if ($iosClass !== null && (!is_string($iosClass) || !$this->nativeClass($iosClass, false))) {
                    $diagnostics[] = $this->error($path.'.iosClass', 'Invalid Swift class name.');
                }
            }
        }

        foreach (['android' => ['sourceDirs', 'resourceDirs', 'assetDirs', 'jniLibDirs', 'localAars'],
                  'ios' => ['sourceDirs', 'resourceDirs']] as $platform => $fields) {
            $configuration = $manifest[$platform] ?? [];
            if (!is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
                $diagnostics[] = $this->error('$.'.$platform, 'Platform configuration must be an object.');
                continue;
            }
            foreach ($fields as $field) {
                $paths = $configuration[$field] ?? [];
                if (!is_array($paths) || !array_is_list($paths)) {
                    $diagnostics[] = $this->error('$.'.$platform.'.'.$field, 'Expected a path list.');
                    continue;
                }
                foreach ($paths as $index => $relative) {
                    $path = '$.'.$platform.'.'.$field.'['.$index.']';
                    if (!is_string($relative) || !$this->safeRelativePath($relative)) {
                        $diagnostics[] = $this->error($path, 'Path must be safe and package-relative.');
                        continue;
                    }
                    $candidate = realpath($packageRoot.DIRECTORY_SEPARATOR.$relative);
                    $root = realpath($packageRoot);
                    if ($candidate === false || $root === false || !str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)) {
                        $diagnostics[] = $this->error($path, 'Path does not exist inside the package.');
                    }
                }
            }
        }

        $ios = $manifest['ios'] ?? [];
        if (is_array($ios) && ($ios === [] || !array_is_list($ios))) {
            $this->validateIos($ios, $diagnostics);
        }

        $idl = $manifest['idl'] ?? null;
        if ($idl !== null && (!is_string($idl) || !$this->safeRelativePath($idl))) {
            $diagnostics[] = $this->error('$.idl', 'IDL must be a safe package-relative path.');
        }

        return new ValidationResult($diagnostics);
    }

    /** @param array<string, mixed> $ios @param list<Diagnostic> $diagnostics */
    private function validateIos(array $ios, array &$diagnostics): void
    {
        $allowed = [
            'minimumVersion', 'sourceDirs', 'resourceDirs', 'swiftPackages', 'frameworks',
            'usageDescriptions', 'entitlements', 'infoPlist', 'extensions',
        ];
        foreach (array_keys($ios) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $diagnostics[] = $this->error('$.ios.'.(string) $key, 'Unknown iOS property.');
            }
        }
        $packages = $ios['swiftPackages'] ?? [];
        if (!is_array($packages) || !array_is_list($packages)) {
            $diagnostics[] = $this->error('$.ios.swiftPackages', 'Expected a Swift package list.');
        } else {
            $urls = [];
            foreach ($packages as $index => $package) {
                $path = '$.ios.swiftPackages['.$index.']';
                if (!is_array($package) || array_is_list($package)) {
                    $diagnostics[] = $this->error($path, 'Swift package must be an object.');
                    continue;
                }
                $url = $package['url'] ?? null;
                if (!is_string($url) || !str_starts_with($url, 'https://') || !str_ends_with($url, '.git')
                    || isset($urls[$url])) {
                    $diagnostics[] = $this->error($path.'.url', 'Expected a unique HTTPS .git URL.');
                } else {
                    $urls[$url] = true;
                }
                $requirement = $package['requirement'] ?? null;
                $kind = is_array($requirement) ? ($requirement['kind'] ?? null) : null;
                $value = is_array($requirement) ? ($requirement['value'] ?? null) : null;
                if (!is_int($kind) || SwiftPackageRequirementKind::tryFrom($kind) === null) {
                    $diagnostics[] = $this->error($path.'.requirement.kind', 'Expected requirement kind integer 1 through 5.');
                }
                if (!is_string($value) || trim($value) === '' || strlen($value) > 160) {
                    $diagnostics[] = $this->error($path.'.requirement.value', 'Invalid requirement value.');
                }
                $products = $package['products'] ?? null;
                if (!is_array($products) || !array_is_list($products) || $products === []
                    || array_any($products, fn (mixed $product): bool => !is_string($product) || !$this->appleIdentifier($product))) {
                    $diagnostics[] = $this->error($path.'.products', 'Expected one or more safe product names.');
                }
            }
        }
        $frameworks = $ios['frameworks'] ?? [];
        if (!is_array($frameworks) || !array_is_list($frameworks)
            || array_any($frameworks, fn (mixed $framework): bool => !is_string($framework) || !$this->appleIdentifier($framework))) {
            $diagnostics[] = $this->error('$.ios.frameworks', 'Expected safe Apple framework names.');
        }
        if (isset($ios['infoPlist']) && (!is_string($ios['infoPlist']) || !$this->safeRelativePath($ios['infoPlist']))) {
            $diagnostics[] = $this->error('$.ios.infoPlist', 'Info.plist must be a safe package-relative path.');
        }
        $extensions = $ios['extensions'] ?? [];
        if (!is_array($extensions) || !array_is_list($extensions)) {
            $diagnostics[] = $this->error('$.ios.extensions', 'Expected an extension list.');
        } else {
            foreach ($extensions as $index => $extension) {
                $path = '$.ios.extensions['.$index.']';
                if (!is_array($extension) || array_is_list($extension)) {
                    $diagnostics[] = $this->error($path, 'Extension must be an object.');
                    continue;
                }
                $kind = $extension['kind'] ?? null;
                if (!is_int($kind) || IosExtensionKind::tryFrom($kind) === null) {
                    $diagnostics[] = $this->error($path.'.kind', 'Expected extension kind integer 1 through 5.');
                }
                if (!is_string($extension['name'] ?? null) || !$this->appleIdentifier($extension['name'])) {
                    $diagnostics[] = $this->error($path.'.name', 'Invalid extension name.');
                }
                if (!is_string($extension['bundleSuffix'] ?? null)
                    || preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/D', $extension['bundleSuffix']) !== 1) {
                    $diagnostics[] = $this->error($path.'.bundleSuffix', 'Invalid extension bundle suffix.');
                }
                if (!is_array($extension['sourceDirs'] ?? null) || ($extension['sourceDirs'] ?? []) === []) {
                    $diagnostics[] = $this->error($path.'.sourceDirs', 'Extension requires source directories.');
                }
            }
        }
    }

    private function appleIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_.+\-]{0,127}$/D', $value) === 1;
    }

    private function semanticVersion(string $value): bool
    {
        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/D', $value) === 1;
    }

    private function nativeClass(string $value, bool $qualified): bool
    {
        $parts = explode('.', $value);
        if ($qualified && count($parts) < 2) {
            return false;
        }
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, "\0")) {
            return false;
        }
        foreach (preg_split('~[/\\\\]+~', $path) ?: [] as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    private function error(string $path, string $message): Diagnostic
    {
        return new Diagnostic(DiagnosticSeverity::Error, $path, $message);
    }

    private function failure(string $path, string $message): ValidationResult
    {
        return new ValidationResult([$this->error($path, $message)]);
    }
}

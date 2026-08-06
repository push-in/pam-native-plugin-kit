<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

use RuntimeException;

final class Scaffolder
{
    public function create(string $package, string $directory): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/D', $package) !== 1) {
            throw new RuntimeException('Package must be a lowercase vendor/name Composer package.');
        }
        if (file_exists($directory)) {
            throw new RuntimeException("Destination already exists: {$directory}");
        }

        [$vendor, $name] = explode('/', $package, 2);
        $class = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $name)));
        $vendorClass = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $vendor)));
        $namespace = $vendorClass.'\\PamNative\\'.$class;
        $androidNamespace = 'dev.pam.'.str_replace(['-', '_', '.'], '', $vendor).'.'.str_replace(['-', '_', '.'], '', $name);
        $swiftModule = $vendorClass.$class;

        $files = [
            'composer.json' => $this->composer($package, $namespace),
            'pam-native.plugin.json' => $this->manifest($package, $namespace, $androidNamespace, $swiftModule),
            'pam-native.idl.json' => $this->idl($vendorClass.'.'.$class),
            'src/'.$class.'PluginProvider.php' => $this->provider($namespace, $class),
            'android/src/main/kotlin/'.str_replace('.', '/', $androidNamespace).'/'.$class.'Module.kt'
                => $this->kotlinModule($androidNamespace, $class),
            'ios/Sources/'.$class.'Module.swift' => $this->swiftModule($class),
            'tests/run.php' => $this->tests($namespace, $class),
            '.github/workflows/ci.yml' => $this->workflow(),
            'README.md' => $this->readme($package),
            'CHANGELOG.md' => "# Changelog\n\n## Unreleased\n\n- Initial implementation.\n",
            'LICENSE' => $this->license(),
        ];

        $created = [];
        try {
            foreach ($files as $relative => $contents) {
                $target = $directory.DIRECTORY_SEPARATOR.$relative;
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                    throw new RuntimeException("Cannot create directory: {$parent}");
                }
                if (file_put_contents($target, $contents, LOCK_EX) === false) {
                    throw new RuntimeException("Cannot write file: {$target}");
                }
                $created[] = $target;
            }
        } catch (RuntimeException $exception) {
            foreach (array_reverse($created) as $target) {
                @unlink($target);
            }
            throw $exception;
        }
    }

    private function composer(string $package, string $namespace): string
    {
        return json_encode([
            'name' => $package,
            'description' => 'A production-ready PAM Native plugin.',
            'type' => 'pam-native-plugin',
            'license' => 'Apache-2.0',
            'require' => ['php' => '^8.4', 'pushinbr/pam-native' => '^0.6.0'],
            'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
            'extra' => ['pam-native' => ['plugin' => 'pam-native.plugin.json']],
            'scripts' => ['test' => 'php tests/run.php'],
            'config' => ['platform-check' => true, 'sort-packages' => true],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function license(): string
    {
        $license = file_get_contents(dirname(__DIR__).'/LICENSE');

        if ($license === false) {
            throw new RuntimeException('Cannot read the bundled Apache-2.0 license.');
        }

        return $license;
    }

    private function manifest(string $package, string $namespace, string $android, string $swift): string
    {
        $class = substr($namespace, (int) strrpos($namespace, '\\') + 1);
        return json_encode([
            '$schema' => 'vendor/pushinbr/pam-native/resources/pam-native.plugin.schema.json',
            'version' => 1,
            'protocol' => 1,
            'pamNative' => ['minimum' => '0.6.0', 'maximumExclusive' => '0.7.0'],
            'php' => ['provider' => $namespace.'\\'.$class.'PluginProvider'],
            'android' => ['namespace' => $android, 'minSdk' => 26, 'sourceDirs' => ['android/src/main/kotlin']],
            'ios' => ['minimumVersion' => '15.0', 'sourceDirs' => ['ios/Sources']],
            'modules' => [[
                'name' => str_replace('/', '.', $package),
                'class' => $android.'.'.$class.'Module',
                'iosClass' => $swift.'.'.$class.'Module',
            ]],
            'views' => [],
            'idl' => 'pam-native.idl.json',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function idl(string $namespace): string
    {
        return json_encode([
            '$schema' => 'vendor/pushinbr/pam-native-plugin-kit/resources/pam-native.idl.schema.json',
            'version' => 1,
            'namespace' => $namespace,
            'enums' => ['OperationState' => ['Pending' => 1, 'Succeeded' => 2, 'Failed' => 3]],
            'records' => ['OperationResult' => ['state' => 'OperationState', 'message' => 'string?']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function provider(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Pam\Native\Plugin\PluginProvider;

final class {$class}PluginProvider implements PluginProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
PHP;
    }

    private function kotlinModule(string $namespace, string $class): string
    {
        return <<<KOTLIN
package {$namespace}

import android.content.Context
import dev.pam.nativeapp.modules.ModuleCompletion
import dev.pam.nativeapp.modules.ModuleResultStatus
import dev.pam.nativeapp.modules.NativeModule

class {$class}Module(
    private val context: Context,
) : NativeModule {
    override fun invoke(method: String, payload: ByteArray, completion: ModuleCompletion) {
        when (method) {
            "health" -> completion.complete(ModuleResultStatus.SUCCESS, payload)
            else -> completion.complete(
                ModuleResultStatus.FAILURE,
                "Unknown method: \$method".toByteArray(Charsets.UTF_8),
            )
        }
    }
}
KOTLIN;
    }

    private function swiftModule(string $class): string
    {
        return <<<SWIFT
import Foundation
import PamNative

public final class {$class}Module: NativeModule, @unchecked Sendable {
    public init() {}

    public func invoke(method: String, payload: Data, completion: @escaping ModuleCompletion) {
        switch method {
        case "health": completion(.success, payload)
        default: completion(.failure, Data("Unknown method: \(method)".utf8))
        }
    }
}
SWIFT;
    }

    private function tests(string $namespace, string $class): string
    {
        $provider = addslashes($namespace.'\\'.$class.'PluginProvider');
        return <<<PHP
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

if (!is_subclass_of('{$provider}', Pam\Native\Plugin\PluginProvider::class)) {
    fwrite(STDERR, "Generated provider contract failed.\n");
    exit(1);
}

fwrite(STDOUT, "Generated provider contract passed.\n");
PHP;
    }

    private function workflow(): string
    {
        return <<<'YAML'
name: CI

on:
  push:
  pull_request:

permissions:
  contents: read

jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer validate --strict
      - run: composer install --no-interaction --prefer-dist
      - run: composer test
YAML;
    }

    private function readme(string $package): string
    {
        return <<<MD
# {$package}

A typed, cross-platform PAM Native plugin generated by the official Plugin Kit.

```bash
composer require {$package}
```

Run `composer test` before publishing. Native CI and capability-specific tests
must be added before the first stable release.
MD;
    }
}

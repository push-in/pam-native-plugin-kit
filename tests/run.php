<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pam\\Native\\PluginKit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});

use Pam\Native\PluginKit\ConformanceRunner;
use Pam\Native\PluginKit\Idl\IdlCompiler;
use Pam\Native\PluginKit\Idl\IdlException;
use Pam\Native\PluginKit\ManifestValidator;
use Pam\Native\PluginKit\Scaffolder;

$tests = [];
$test = static function (string $name, Closure $callback) use (&$tests): void {
    $tests[$name] = $callback;
};
$expect = static function (bool $condition, string $message = 'Expectation failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('validates complete cross-platform manifests', static function () use ($expect): void {
    $root = sys_get_temp_dir().'/pam-plugin-kit-'.bin2hex(random_bytes(8));
    mkdir($root.'/android/src', 0777, true);
    mkdir($root.'/ios/Sources', 0777, true);
    $result = (new ManifestValidator())->validate([
        'version' => 1,
        'protocol' => 1,
        'pamNative' => ['minimum' => '0.6.0', 'maximumExclusive' => '0.7.0'],
        'capabilities' => [
            'required' => ['runtime.modules.v1', 'wire.binary.v1'],
            'optional' => ['renderer.incremental.v1'],
        ],
        'android' => ['sourceDirs' => ['android/src']],
        'ios' => ['sourceDirs' => ['ios/Sources']],
        'modules' => [[
            'name' => 'example.session',
            'class' => 'dev.pam.example.SessionModule',
            'iosClass' => 'PamExample.SessionModule',
        ]],
    ], $root);
    $expect($result->passed(), 'A complete manifest must pass.');
    rmdir($root.'/android/src');
    rmdir($root.'/android');
    rmdir($root.'/ios/Sources');
    rmdir($root.'/ios');
    rmdir($root);
});

$test('rejects malformed and duplicated capabilities', static function () use ($expect): void {
    $result = (new ManifestValidator())->validate([
        'version' => 1,
        'protocol' => 1,
        'pamNative' => ['minimum' => '0.8.0', 'maximumExclusive' => '2.0.0'],
        'capabilities' => [
            'required' => ['wire.binary.v1'],
            'optional' => ['wire.binary.v1', 'INVALID'],
        ],
    ], dirname(__DIR__));
    $expect(!$result->passed(), 'Duplicated or malformed capabilities must fail.');
    $paths = array_map(static fn ($diagnostic): string => $diagnostic->path, $result->diagnostics);
    $expect(in_array('$.capabilities.optional[0]', $paths, true));
    $expect(in_array('$.capabilities.optional[1]', $paths, true));
});

$test('rejects duplicate bindings and traversal', static function () use ($expect): void {
    $result = (new ManifestValidator())->validate([
        'version' => 1,
        'protocol' => 1,
        'pamNative' => ['minimum' => '0.6.0', 'maximumExclusive' => '0.7.0'],
        'android' => ['sourceDirs' => ['../outside']],
        'modules' => [
            ['name' => 'example.echo', 'class' => 'dev.pam.Echo'],
            ['name' => 'example.echo', 'class' => 'dev.pam.Other'],
        ],
    ], dirname(__DIR__));
    $expect(!$result->passed(), 'Unsafe duplicated manifest must fail.');
    $paths = array_map(static fn ($diagnostic): string => $diagnostic->path, $result->diagnostics);
    $expect(in_array('$.android.sourceDirs[0]', $paths, true));
    $expect(in_array('$.modules[1].name', $paths, true));
});

$test('generates equivalent integer enums and records', static function () use ($expect): void {
    $generated = (new IdlCompiler())->compile([
        'version' => 1,
        'namespace' => 'Pam.Auth',
        'enums' => [
            'SessionState' => ['Unknown' => 1, 'Authenticated' => 2, 'Expired' => 3],
        ],
        'records' => [
            'Session' => ['identifier' => 'string', 'state' => 'SessionState', 'email' => 'string?'],
        ],
    ]);
    $expect(str_contains($generated['php'], 'enum SessionState: int'));
    $expect(str_contains($generated['php'], 'case Authenticated = 2;'));
    $expect(str_contains($generated['kotlin'], 'enum class SessionState(val value: Int)'));
    $expect(str_contains($generated['swift'], 'public enum SessionState: Int, Sendable'));
    $expect(str_contains($generated['php'], 'final readonly class Session'));
    $expect(str_contains($generated['kotlin'], 'data class Session'));
    $expect(str_contains($generated['swift'], 'public struct Session: Sendable'));
});

$test('rejects non-sequential coded variants', static function () use ($expect): void {
    try {
        (new IdlCompiler())->compile([
            'version' => 1,
            'namespace' => 'Pam.Auth',
            'enums' => ['SessionState' => ['Unknown' => 1, 'Expired' => 3]],
        ]);
        $expect(false, 'Non-sequential enum unexpectedly compiled.');
    } catch (IdlException $exception) {
        $expect(str_contains($exception->getMessage(), 'expected 2'));
    }
});

$test('scaffolds a certifiable cross-platform package', static function () use ($expect): void {
    $root = sys_get_temp_dir().'/pam-plugin-scaffold-'.bin2hex(random_bytes(8));
    (new Scaffolder())->create('acme/pam-native-example', $root);
    $expect(is_file($root.'/composer.json'));
    $expect(is_file($root.'/android/src/main/kotlin/dev/pam/acme/pamnativeexample/PamNativeExampleModule.kt'));
    $expect(is_file($root.'/ios/Sources/PamNativeExampleModule.swift'));
    $expect(is_file($root.'/.github/workflows/ci.yml'));
    $composer = file_get_contents($root.'/composer.json');
    $workflow = file_get_contents($root.'/.github/workflows/ci.yml');
    $expect(is_string($composer) && str_contains($composer, '"php": "^8.5"'));
    $expect(is_string($composer) && str_contains($composer, '"pushinbr/pam-native": "^0.8 || ^0.9 || ^0.10 || ^1.0"'));
    $expect(is_string($workflow) && str_contains($workflow, "php-version: '8.5'"));
    $result = (new ManifestValidator())->validateFile($root.'/pam-native.plugin.json');
    $expect($result->passed(), 'Generated manifest must pass Plugin Kit validation.');
    $generated = (new IdlCompiler())->compileFile($root.'/pam-native.idl.json');
    $expect(str_contains($generated['swift'], 'OperationState'));
    $conformance = (new ConformanceRunner())->run($root);
    $expect($conformance->passed(), 'Generated plugin must pass portable conformance.');
    $report = $conformance->toArray();
    $expect($report['schemaVersion'] === 1);
    $expect($report['surfaceCode'] === 2);
    $expect($report['resultCode'] === 1);
    $expect(array_column($report['checks'], 'checkCode') === range(1, 7));
    foreach ($report['checks'] as $check) {
        $expect($check['resultCode'] === 1);
        $expect(is_string($check['evidenceSha256']) && strlen($check['evidenceSha256']) === 64);
    }
    $command = escapeshellarg(PHP_BINARY).' '
        .escapeshellarg(dirname(__DIR__).'/bin/pam-native-plugin').' conformance '
        .escapeshellarg($root).' --json';
    exec($command, $lines, $exitCode);
    $expect($exitCode === 0, 'Conformance CLI must accept a generated plugin.');
    $cliReport = json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
    $expect($cliReport === $report, 'CLI and library conformance reports must be identical.');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});

$test('fails conformance without reading symlinked IDL evidence', static function () use ($expect): void {
    $root = sys_get_temp_dir().'/pam-plugin-conformance-'.bin2hex(random_bytes(8));
    (new Scaffolder())->create('acme/pam-native-example', $root);
    $outside = sys_get_temp_dir().'/pam-plugin-outside-'.bin2hex(random_bytes(8)).'.json';
    file_put_contents($outside, '{"version":1,"namespace":"Outside.Contract"}', LOCK_EX);
    unlink($root.'/pam-native.idl.json');
    symlink($outside, $root.'/pam-native.idl.json');

    $report = (new ConformanceRunner())->run($root);
    $expect(!$report->passed(), 'Symlinked IDL must fail conformance.');
    $checks = $report->toArray()['checks'];
    $expect($checks[1]['checkCode'] === 2 && $checks[1]['resultCode'] === 2);
    $expect($checks[2]['checkCode'] === 3 && $checks[2]['resultCode'] === 2);
    $expect($checks[1]['evidenceSha256'] === null);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
    unlink($outside);
});

$test('validates integer-coded iOS integration metadata', static function () use ($expect): void {
    $root = sys_get_temp_dir().'/pam-plugin-ios-'.bin2hex(random_bytes(8));
    mkdir($root.'/ios/Sources', 0777, true);
    $manifest = [
        'version' => 1,
        'protocol' => 1,
        'pamNative' => ['minimum' => '0.6.0', 'maximumExclusive' => '0.7.0'],
        'ios' => [
            'sourceDirs' => ['ios/Sources'],
            'swiftPackages' => [[
                'url' => 'https://github.com/vendor/sdk.git',
                'requirement' => ['kind' => 2, 'value' => '4.2.0'],
                'products' => ['VendorSDK'],
            ]],
            'frameworks' => ['AuthenticationServices'],
            'extensions' => [],
        ],
    ];
    $validator = new ManifestValidator();
    $expect($validator->validate($manifest, $root)->passed());
    $manifest['ios']['swiftPackages'][0]['requirement']['kind'] = 0;
    $result = $validator->validate($manifest, $root);
    $expect(!$result->passed(), 'String/zero-based iOS variants must fail.');
    $paths = array_map(static fn ($diagnostic): string => $diagnostic->path, $result->diagnostics);
    $expect(in_array('$.ios.swiftPackages[0].requirement.kind', $paths, true));
    rmdir($root.'/ios/Sources');
    rmdir($root.'/ios');
    rmdir($root);
});

$failures = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $throwable) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}: {$throwable->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

use JsonException;
use Pam\Native\PluginKit\Idl\IdlCompiler;
use Throwable;

final class ConformanceRunner
{
    private const int MAX_DOCUMENT_BYTES = 1_048_576;
    private const int MAX_SOURCE_BYTES = 16_777_216;
    private const int MAX_SOURCE_FILES = 256;

    public function run(string $packageRoot): ConformanceReport
    {
        $root = realpath($packageRoot);
        if ($root === false || !is_dir($root) || is_link($packageRoot)) {
            return new ConformanceReport($this->allFailed('Package root must be a regular directory.'));
        }

        $manifestPath = $root.'/pam-native.plugin.json';
        $manifestSafe = $this->regularPackageFile($root, 'pam-native.plugin.json');
        $manifestResult = $manifestSafe ? (new ManifestValidator())->validateFile($manifestPath) : null;
        $manifest = $manifestSafe ? $this->document($manifestPath) : null;
        $manifestPassed = $manifestResult !== null && $manifestResult->passed() && $manifest !== null;
        $checks = [];
        $checks[] = $this->check(
            ConformanceCheckCode::Manifest,
            $manifestPassed,
            'Plugin manifest is valid and bounded.',
            $manifest === null ? null : (hash_file('sha256', $manifestPath) ?: null),
        );

        $idlRelative = $manifestPassed && is_string($manifest['idl'] ?? null)
            ? $manifest['idl']
            : null;
        $idlPath = $idlRelative !== null && $this->regularPackageFile($root, $idlRelative)
            ? $root.'/'.$manifest['idl']
            : null;
        $generated = null;
        try {
            if ($idlPath === null || !$this->regularBoundedFile($idlPath)) {
                throw new \RuntimeException('A bounded regular IDL is required.');
            }
            $generated = (new IdlCompiler())->compileFile($idlPath);
            $idlPassed = true;
        } catch (Throwable) {
            $idlPassed = false;
        }
        $checks[] = $this->check(
            ConformanceCheckCode::Idl,
            $idlPassed,
            'Typed IDL compiles for PHP, Kotlin and Swift.',
            $idlPassed && $idlPath !== null ? (hash_file('sha256', $idlPath) ?: null) : null,
        );

        $deterministic = false;
        $generatedDigest = null;
        if ($generated !== null && $idlPath !== null) {
            try {
                $second = (new IdlCompiler())->compileFile($idlPath);
                $deterministic = $generated === $second && array_keys($generated) === ['php', 'kotlin', 'swift'];
                if ($deterministic) {
                    $generatedDigest = hash('sha256', $generated['php']."\0".$generated['kotlin']."\0".$generated['swift']);
                }
            } catch (Throwable) {
                $deterministic = false;
            }
        }
        $checks[] = $this->check(
            ConformanceCheckCode::DeterministicGeneration,
            $deterministic,
            'Cross-language generation is byte-for-byte deterministic.',
            $generatedDigest,
        );

        [$composerPassed, $composerDigest] = $this->composer($root.'/composer.json');
        $checks[] = $this->check(
            ConformanceCheckCode::ComposerMetadata,
            $composerPassed,
            'Composer metadata exposes a PAM Native plugin and test command.',
            $composerDigest,
        );

        foreach ([
            [ConformanceCheckCode::AndroidSources, 'android', 'sourceDirs', '.kt'],
            [ConformanceCheckCode::IosSources, 'ios', 'sourceDirs', '.swift'],
        ] as [$code, $platform, $field, $extension]) {
            $paths = $manifestPassed && is_array($manifest[$platform] ?? null)
                ? ($manifest[$platform][$field] ?? null)
                : null;
            [$passed, $digest] = $this->sourceEvidence($root, $paths, $extension);
            $checks[] = $this->check(
                $code,
                $passed,
                ucfirst($platform).' declares bounded native source evidence.',
                $digest,
            );
        }

        $testPath = $root.'/tests/run.php';
        $testPassed = $this->regularBoundedFile($testPath);
        $checks[] = $this->check(
            ConformanceCheckCode::TestEntrypoint,
            $testPassed,
            'Portable plugin test entrypoint is present and bounded.',
            $testPassed ? (hash_file('sha256', $testPath) ?: null) : null,
        );

        return new ConformanceReport($checks);
    }

    /** @return list<ConformanceCheck> */
    private function allFailed(string $message): array
    {
        return array_map(
            fn (ConformanceCheckCode $code): ConformanceCheck => $this->check($code, false, $message, null),
            ConformanceCheckCode::cases(),
        );
    }

    private function check(ConformanceCheckCode $code, bool $passed, string $message, ?string $digest): ConformanceCheck
    {
        return new ConformanceCheck(
            $code,
            $passed ? ConformanceResultCode::Passed : ConformanceResultCode::Failed,
            $message,
            $passed ? $digest : null,
        );
    }

    /** @return array<string, mixed>|null */
    private function document(string $path): ?array
    {
        if (!$this->regularBoundedFile($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        try {
            $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    private function regularBoundedFile(string $path): bool
    {
        return !is_link($path)
            && is_file($path)
            && ($size = filesize($path)) !== false
            && $size > 0
            && $size <= self::MAX_DOCUMENT_BYTES;
    }

    private function regularPackageFile(string $root, string $relative): bool
    {
        if (!$this->safeRelativePath($relative)) {
            return false;
        }
        $current = $root;
        foreach (preg_split('~[/\\\\]+~', $relative) ?: [] as $part) {
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current)) {
                return false;
            }
        }
        $resolved = realpath($current);
        return $resolved !== false
            && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
            && $this->regularBoundedFile($resolved);
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

    /** @return array{bool, ?string} */
    private function composer(string $path): array
    {
        $composer = $this->document($path);
        $passed = is_array($composer)
            && ($composer['type'] ?? null) === 'pam-native-plugin'
            && is_string($composer['name'] ?? null)
            && is_array($composer['require'] ?? null)
            && is_string($composer['require']['pushinbr/pam-native'] ?? null)
            && ($composer['extra']['pam-native']['plugin'] ?? null) === 'pam-native.plugin.json'
            && is_string($composer['scripts']['test'] ?? null);
        return [$passed, $passed ? hash_file('sha256', $path) ?: null : null];
    }

    /** @param mixed $paths @return array{bool, ?string} */
    private function sourceEvidence(string $root, mixed $paths, string $extension): array
    {
        if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
            return [false, null];
        }
        $entries = [];
        $bytes = 0;
        foreach ($paths as $relative) {
            if (!is_string($relative) || !$this->safeRelativePath($relative)) {
                return [false, null];
            }
            $directory = $root;
            foreach (preg_split('~[/\\\\]+~', $relative) ?: [] as $part) {
                $directory .= DIRECTORY_SEPARATOR.$part;
                if (is_link($directory)) {
                    return [false, null];
                }
            }
            $resolvedDirectory = realpath($directory);
            if ($resolvedDirectory === false
                || !str_starts_with($resolvedDirectory, $root.DIRECTORY_SEPARATOR)
                || !is_dir($resolvedDirectory)) {
                return [false, null];
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resolvedDirectory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                if ($entry->isLink() || !$entry->isFile()) {
                    return [false, null];
                }
                $path = $entry->getPathname();
                if (!str_ends_with($path, $extension)) {
                    continue;
                }
                $size = $entry->getSize();
                $bytes += $size;
                if ($size <= 0 || $bytes > self::MAX_SOURCE_BYTES || count($entries) >= self::MAX_SOURCE_FILES) {
                    return [false, null];
                }
                $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                if (isset($entries[$name])) {
                    return [false, null];
                }
                $digest = hash_file('sha256', $path);
                if ($digest === false) {
                    return [false, null];
                }
                $entries[$name] = $digest;
            }
        }
        if ($entries === []) {
            return [false, null];
        }
        ksort($entries, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($entries as $name => $digest) {
            hash_update($context, $name."\0".$digest."\n");
        }
        return [true, hash_final($context)];
    }
}

<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

final readonly class ValidationResult
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(public array $diagnostics)
    {
    }

    public function passed(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === DiagnosticSeverity::Error) {
                return false;
            }
        }

        return true;
    }
}

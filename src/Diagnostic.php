<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

final readonly class Diagnostic
{
    public function __construct(
        public DiagnosticSeverity $severity,
        public string $path,
        public string $message,
    ) {
    }
}

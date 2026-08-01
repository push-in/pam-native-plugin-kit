<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

enum DiagnosticSeverity: int
{
    case Error = 1;
    case Warning = 2;
}

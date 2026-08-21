<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

enum ConformanceCheckCode: int
{
    case Manifest = 1;
    case Idl = 2;
    case DeterministicGeneration = 3;
    case ComposerMetadata = 4;
    case AndroidSources = 5;
    case IosSources = 6;
    case TestEntrypoint = 7;
}

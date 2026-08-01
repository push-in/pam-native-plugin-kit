<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit\Ios;

enum SwiftPackageRequirementKind: int
{
    case Exact = 1;
    case From = 2;
    case Branch = 3;
    case Revision = 4;
    case UpToNextMinor = 5;
}

<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

enum ConformanceSurfaceCode: int
{
    case Runtime = 1;
    case Native = 2;
    case Desktop = 3;
}

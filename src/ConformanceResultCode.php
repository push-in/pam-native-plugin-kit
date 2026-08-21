<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

enum ConformanceResultCode: int
{
    case Passed = 1;
    case Failed = 2;
}

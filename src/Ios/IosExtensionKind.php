<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit\Ios;

enum IosExtensionKind: int
{
    case Share = 1;
    case Widget = 2;
    case NotificationService = 3;
    case Intents = 4;
    case LiveActivity = 5;
}

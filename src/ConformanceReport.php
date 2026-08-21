<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

final readonly class ConformanceReport
{
    /** @param list<ConformanceCheck> $checks */
    public function __construct(public array $checks)
    {
    }

    public function passed(): bool
    {
        return array_all(
            $this->checks,
            static fn (ConformanceCheck $check): bool => $check->resultCode === ConformanceResultCode::Passed,
        );
    }

    /** @return array{schemaVersion: int, surfaceCode: int, resultCode: int, checks: list<array{checkCode: int, resultCode: int, message: string, evidenceSha256: ?string}>} */
    public function toArray(): array
    {
        return [
            'schemaVersion' => 1,
            'surfaceCode' => ConformanceSurfaceCode::Native->value,
            'resultCode' => ($this->passed() ? ConformanceResultCode::Passed : ConformanceResultCode::Failed)->value,
            'checks' => array_map(
                static fn (ConformanceCheck $check): array => $check->toArray(),
                $this->checks,
            ),
        ];
    }
}

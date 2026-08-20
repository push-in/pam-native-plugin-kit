<?php

declare(strict_types=1);

namespace Pam\Native\PluginKit;

final readonly class ConformanceCheck
{
    public function __construct(
        public ConformanceCheckCode $checkCode,
        public ConformanceResultCode $resultCode,
        public string $message,
        public ?string $evidenceSha256 = null,
    ) {
    }

    /** @return array{checkCode: int, resultCode: int, message: string, evidenceSha256: ?string} */
    public function toArray(): array
    {
        return [
            'checkCode' => $this->checkCode->value,
            'resultCode' => $this->resultCode->value,
            'message' => $this->message,
            'evidenceSha256' => $this->evidenceSha256,
        ];
    }
}

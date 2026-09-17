<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;

/** Внутренняя причина переводится в протокол APK только на границе HTTP. */
final class AccessDenied extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $userMessage = null)
    {
        parent::__construct($reason);
    }
}

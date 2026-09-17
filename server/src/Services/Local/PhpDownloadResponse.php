<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;

use Symfony\Component\HttpFoundation\{BinaryFileResponse, Request};

/**
 * Передача файла самим PHP, без X-Accel-Redirect/X-Sendfile и внешних redirect.
 * Symfony читает файл порциями и реализует Range/HEAD/416 без загрузки всего ZIP в память.
 * Переопределение действует только на ответ модуля и не меняет глобальные настройки Laravel.
 */
final class PhpDownloadResponse extends BinaryFileResponse
{
    public function prepare(Request $request): static
    {
        $localRequest = clone $request;
        $localRequest->headers = clone $request->headers;
        $localRequest->headers->remove('X-Sendfile-Type');
        $localRequest->headers->remove('X-Accel-Mapping');
        parent::prepare($localRequest);
        return $this;
    }
}

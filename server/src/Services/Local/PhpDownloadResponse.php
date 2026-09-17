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
        // Symfony 7 может проигнорировать открытый диапазон bytes=N-, когда N за EOF.
        // Для Android resume возвращаем явный 416, а не повторно весь архив с 200.
        $range = (string) $localRequest->headers->get('Range', '');
        $ifRange = $localRequest->headers->get('If-Range');
        $matches = $ifRange === null || $ifRange === $this->getEtag()
            || $ifRange === $this->getLastModified()?->format('D, d M Y H:i:s') . ' GMT';
        if ($localRequest->isMethod('GET') && $matches && $this->getStatusCode() === 200
            && preg_match('/^bytes=(\d+)-(\d*)$/D', $range, $parts)
            && (float) $parts[1] >= $this->getFile()->getSize()) {
            $this->setStatusCode(416);
            $this->headers->set('Content-Range', 'bytes */' . $this->getFile()->getSize());
            $this->headers->set('Content-Length', '0');
            $this->maxlen = 0;
        }
        return $this;
    }
}

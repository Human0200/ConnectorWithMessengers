<?php

declare(strict_types=1);

namespace BitrixTelegram\Handlers;

/**
 * Контракт для хендлеров входящих вебхуков.
 * Контроллер делегирует обработку конкретному хендлеру — сам ничего не знает об API.
 */
interface IncomingHandlerInterface
{
    /**
     * Обработать входящий вебхук и вернуть результат.
     *
     * @param array $data  Тело запроса (уже декодированный JSON)
     * @return array       ['status' => 'ok'|'error', ...]
     */
    public function handle(array $data): array;
}
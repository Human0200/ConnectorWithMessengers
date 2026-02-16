<?php

declare(strict_types=1);

namespace BitrixTelegram\Messengers;

interface MessengerInterface
{
    /**
     * Отправить текстовое сообщение
     */
    public function sendMessage(string $chatId, string $text): array;

    /**
     * Отправить фото
     */
    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null): array;

    /**
     * Отправить документ
     */
    public function sendDocument(string $chatId, string $documentUrl, ?string $caption = null, ?array $fileData = null): array;

    /**
     * Отправить голосовое сообщение
     */
    public function sendVoice(string $chatId, string $voiceUrl): array;

    /**
     * Отправить видео
     */
    public function sendVideo(string $chatId, string $videoUrl, ?string $caption = null): array;

    /**
     * Получить информацию о файле
     */
    public function getFile(string $fileId): ?array;

    /**
     * Получить URL файла
     */
    public function getFileUrl(string $filePath): string;

    /**
     * Установить вебхук
     */
    public function setWebhook(string $webhookUrl): array;

    /**
     * Получить информацию о боте/аккаунте
     */
    public function getInfo(): array;

    /**
     * Преобразовать сообщение из мессенджера в универсальный формат
     */
    public function normalizeIncomingMessage(array $message): array;

    /**
     * Преобразовать универсальное сообщение в формат мессенджера
     */
    public function denormalizeOutgoingMessage(array $message): array;

    /**
     * Получить тип мессенджера
     */
    public function getType(): string;

    /**
     * Установить домен для текущей операции (требуется для Max)
     */
    public function setDomain(string $domain): void;

    /**
     * Получить текущий домен
     */
    public function getDomain(): ?string;
}
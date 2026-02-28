<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Handlers\BitrixToMessengerHandler;
use BitrixTelegram\Handlers\TelegramBotIncomingHandler;
use BitrixTelegram\Handlers\MaxBotIncomingHandler;
use BitrixTelegram\Handlers\MessengerToBitrixHandler;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\TelegramBotService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\MessageDetector;

/**
 * Контроллер вебхуков.
 *
 * Единственная ответственность — определить источник запроса и
 * передать управление нужному хендлеру. Никаких прямых вызовов API
 * или бизнес-логики здесь нет.
 */
class WebhookController
{
    public function __construct(
        private BitrixToMessengerHandler    $bitrixToMessengerHandler,
        private TelegramBotIncomingHandler  $telegramBotIncomingHandler,
        private MaxBotIncomingHandler       $maxBotIncomingHandler,
        private MessengerFactory            $messengerFactory,
        private BitrixService               $bitrixService,
        private TelegramBotService          $telegramBotService,
        private TokenRepository             $tokenRepository,
        private ProfileRepository           $profileRepository,
        private ChatRepository              $chatRepository,
        private Logger                      $logger,
        private MessageDetector             $detector
    ) {}

    public function handleWebhook(array $data): array
    {
        $source = $this->detector->detectSource($data);

        $this->logger->info('Webhook received', [
            'source'   => $source,
            'has_data' => !empty($data),
        ]);

        return match ($source) {
            MessageDetector::SOURCE_BITRIX        => $this->bitrixToMessengerHandler->handle($data),
            MessageDetector::SOURCE_TELEGRAM_BOT  => $this->telegramBotIncomingHandler->handle($data),
            MessageDetector::SOURCE_TELEGRAM_USER => $this->makeMessengerToBitrixHandler('telegram_user')->handle($data),
            MessageDetector::SOURCE_MAX           => $this->handleMax($data),
            default                               => $this->handleUnknownSource($data),
        };
    }

    /**
     * Max может прийти двумя путями:
     * — ?max_token=xxx в URL → профильный бот (MaxBotIncomingHandler)
     * — без токена → пользовательский аккаунт (MessengerToBitrixHandler)
     */
    private function handleMax(array $data): array
    {
        if (!empty($_GET['max_token'])) {
            return $this->maxBotIncomingHandler->handle($data);
        }

        return $this->makeMessengerToBitrixHandler('max')->handle($data);
    }

    private function handleUnknownSource(array $data): array
    {
        $this->logger->warning('Webhook: unknown source', ['data' => $data]);
        return ['status' => 'error', 'message' => 'Unknown source'];
    }

    private function makeMessengerToBitrixHandler(string $messengerType): MessengerToBitrixHandler
    {
        return new MessengerToBitrixHandler(
            $this->bitrixService,
            $this->messengerFactory,
            $this->tokenRepository,
            $this->profileRepository,
            $this->chatRepository,
            $this->logger,
            $messengerType
        );
    }
}
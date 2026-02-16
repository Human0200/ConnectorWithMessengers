<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use Exception;

class BitrixService
{
    private const TYPE_TRANSPORT = 'json';

    private TokenRepository $tokenRepository;
    private TokenService $tokenService;
    private Logger $logger;

    public function __construct(
        TokenRepository $tokenRepository,
        TokenService $tokenService,
        Logger $logger
    ) {
        $this->tokenRepository = $tokenRepository;
        $this->tokenService = $tokenService;
        $this->logger = $logger;
    }

    public function call(string $method, array $params, string $domain): array
    {
        $result = $this->makeRequest($method, $params, $domain);

        // Обработка истекшего токена
        if ($this->isExpiredTokenError($result)) {
            $this->logger->info('Token expired, refreshing', ['domain' => $domain]);
            
            try {
                $newToken = $this->tokenService->refreshToken($domain);
                $params['auth'] = $newToken;
                $result = $this->makeRequest($method, $params, $domain);
            } catch (Exception $e) {
                $this->logger->logException($e, 'Token refresh failed');
            }
        }

        return $result;
    }

    public function registerConnector(string $connectorId, string $handlerUrl): array
    {
        return $this->call('imconnector.register', [
            'ID' => $connectorId,
            'NAME' => 'Telegram Integration',
            'ICON' => $this->getConnectorIcon(),
            'ICON_DISABLED' => $this->getConnectorIcon(true),
            'PLACEMENT_HANDLER' => $handlerUrl,
        ], '');
    }

    public function activateConnector(
        string $connectorId,
        int $lineId,
        bool $active,
        string $domain
    ): array {
        return $this->call('imconnector.activate', [
            'CONNECTOR' => $connectorId,
            'LINE' => $lineId,
            'ACTIVE' => $active ? 1 : 0,
        ], $domain);
    }

    public function sendMessages(
        string $connectorId,
        int $lineId,
        array $messages,
        string $domain
    ): array {
        return $this->call('imconnector.send.messages', [
            'CONNECTOR' => $connectorId,
            'LINE' => $lineId,
            'MESSAGES' => $messages,
        ], $domain);
    }

    public function sendDeliveryStatus(
        string $connectorId,
        int $lineId,
        array $messages,
        string $domain
    ): array {
        return $this->call('imconnector.send.status.delivery', [
            'CONNECTOR' => $connectorId,
            'LINE' => $lineId,
            'MESSAGES' => $messages,
        ], $domain);
    }

    public function sendErrorStatus(
        string $connectorId,
        int $lineId,
        array $messages,
        string $domain
    ): array {
        return $this->call('imconnector.send.status.error', [
            'CONNECTOR' => $connectorId,
            'LINE' => $lineId,
            'MESSAGES' => $messages,
        ], $domain);
    }

    public function bindEvent(string $event, string $handlerUrl, string $domain): array
    {
        return $this->call('event.bind', [
            'event' => $event,
            'handler' => $handlerUrl,
        ], $domain);
    }

    public function unregisterConnector(string $connectorId, string $domain): array
    {
        return $this->call('imconnector.unregister', [
            'ID' => $connectorId,
        ], $domain);
    }

    private function makeRequest(string $method, array $params, string $domain): array
    {
        $tokenData = $this->tokenRepository->findByDomain($domain);
        
        if (!$tokenData) {
            return [
                'error' => 'no_install_app',
                'error_information' => 'Application not installed',
            ];
        }

        $url = sprintf(
            '%s%s.%s',
            $tokenData['client_endpoint'] ?? 'https://' . $domain . '/rest/',
            $method,
            self::TYPE_TRANSPORT
        );

        // Добавляем токен авторизации
        if (empty($tokenData['is_web_hook']) || $tokenData['is_web_hook'] !== 'Y') {
            if (!isset($params['auth'])) {
                $params['auth'] = $this->tokenService->getValidToken($domain);
            }
        }

        $postFields = http_build_query($params);

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_USERAGENT => 'Bitrix-Telegram Integration 1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $info = curl_getinfo($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $this->logger->error('Bitrix API curl error', [
                    'method' => $method,
                    'error' => $curlError,
                ]);
                return [
                    'error' => 'curl_error',
                    'error_information' => $curlError,
                ];
            }

            $result = json_decode($response, true);

            $this->logger->debug('Bitrix API call', [
                'method' => $method,
                'url' => $url,
                'http_code' => $info['http_code'],
                'has_error' => !empty($result['error']),
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->logException($e, 'Bitrix API exception');
            return [
                'error' => 'exception',
                'error_information' => $e->getMessage(),
            ];
        }
    }

    private function isExpiredTokenError(array $result): bool
    {
        if (isset($result['error']) && $result['error'] === 'expired_token') {
            return true;
        }

        if (isset($result['error_description'])) {
            $description = $result['error_description'];
            return strpos($description, 'expired_token') !== false ||
                   strpos($description, 'The access token provided has expired') !== false;
        }

        return false;
    }

    private function getConnectorIcon(bool $disabled = false): array
    {
        return [
            'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20version%3D%221.1%22%20id%3D%22Layer_1%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20x%3D%220px%22%20y%3D%220px%22%0A%09%20viewBox%3D%220%200%2070%2071%22%20style%3D%22enable-background%3Anew%200%200%2070%2071%3B%22%20xml%3Aspace%3D%22preserve%22%3E%0A%3Cpath%20fill%3D%22%230C99BA%22%20class%3D%22st0%22%20d%3D%22M34.7%2C64c-11.6%2C0-22-7.1-26.3-17.8C4%2C35.4%2C6.4%2C23%2C14.5%2C14.7c8.1-8.2%2C20.4-10.7%2C31-6.2%0A%09c12.5%2C5.4%2C19.6%2C18.8%2C17%2C32.2C60%2C54%2C48.3%2C63.8%2C34.7%2C64L34.7%2C64z%20M27.8%2C29c0.8-0.9%2C0.8-2.3%2C0-3.2l-1-1.2h19.3c1-0.1%2C1.7-0.9%2C1.7-1.8%0A%09v-0.9c0-1-0.7-1.8-1.7-1.8H26.8l1.1-1.2c0.8-0.9%2C0.8-2.3%2C0-3.2c-0.4-0.4-0.9-0.7-1.5-0.7s-1.1%2C0.2-1.5%2C0.7l-4.6%2C5.1%0A%09c-0.8%2C0.9-0.8%2C2.3%2C0%2C3.2l4.6%2C5.1c0.4%2C0.4%2C0.9%2C0.7%2C1.5%2C0.7C26.9%2C29.6%2C27.4%2C29.4%2C27.8%2C29L27.8%2C29z%20M44%2C41c-0.5-0.6-1.3-0.8-2-0.6%0A%09c-0.7%2C0.2-1.3%2C0.9-1.5%2C1.6c-0.2%2C0.8%2C0%2C1.6%2C0.5%2C2.2l1%2C1.2H22.8c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h19.3l-1%2C1.2%0A%09c-0.5%2C0.6-0.7%2C1.4-0.5%2C2.2c0.2%2C0.8%2C0.7%2C1.4%2C1.5%2C1.6c0.7%2C0.2%2C1.5%2C0%2C2-0.6l4.6-5.1c0.8-0.9%2C0.8-2.3%2C0-3.2L44%2C41z%20M23.5%2C32.8%0A%09c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h23.4c1-0.1%2C1.7-0.9%2C1.7-1.8v-0.9c0-1-0.7-1.8-1.7-1.9L23.5%2C32.8L23.5%2C32.8z%22/%3E%0A%3C/svg%3E%0A',
            'COLOR' => $disabled ? '#ffb3a3' : '#a6ffa3',
            'SIZE' => '100%',
            'POSITION' => 'center',
        ];
    }
}
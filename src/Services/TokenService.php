<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use Exception;

class TokenService
{
    private TokenRepository $tokenRepository;
    private Logger $logger;
    private string $oauthUrl;

    public function __construct(
        TokenRepository $tokenRepository,
        Logger $logger,
        array $config
    ) {
        $this->tokenRepository = $tokenRepository;
        $this->logger = $logger;
        $this->oauthUrl = $config['oauth_url'];
    }

    public function refreshToken(string $domain): string
    {
        $this->logger->info('Refreshing token', ['domain' => $domain]);
        
        $tokenData = $this->tokenRepository->findByDomain($domain);
        
        if (!$tokenData || empty($tokenData['refresh_token'])) {
            throw new Exception("No refresh token available for domain: $domain");
        }

        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => $tokenData['client_id'],
            'client_secret' => $tokenData['client_secret'],
            'refresh_token' => $tokenData['refresh_token'],
        ];
        
        $url = $this->oauthUrl . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->logger->error('Token refresh failed', [
                'domain' => $domain,
                'http_code' => $httpCode,
                'response' => $response,
            ]);
            throw new Exception("Token refresh failed with HTTP code: $httpCode");
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            $errorMessage = $result['error_description'] ?? $result['error'];
            $this->logger->error('Token refresh error', [
                'domain' => $domain,
                'error' => $errorMessage,
            ]);
            throw new Exception("Token refresh error: $errorMessage");
        }
        
        if (!isset($result['access_token']) || !isset($result['expires_in'])) {
            throw new Exception('Invalid token response');
        }

        $newExpires = time() + (int) $result['expires_in'];
        $newRefreshToken = $result['refresh_token'] ?? $tokenData['refresh_token'];
        
        $updated = $this->tokenRepository->updateAccessToken(
            $domain,
            $result['access_token'],
            $newExpires,
            $newRefreshToken
        );
        
        if (!$updated) {
            throw new Exception('Failed to update tokens in database');
        }
        
        $this->logger->info('Token refreshed successfully', [
            'domain' => $domain,
            'expires_at' => date('Y-m-d H:i:s', $newExpires),
        ]);
        
        return $result['access_token'];
    }

    public function isTokenExpired(string $domain): bool
    {
        $tokenData = $this->tokenRepository->findByDomain($domain);
        
        if (!$tokenData || !isset($tokenData['token_expires'])) {
            return true;
        }
        
        // Обновляем токен за 5 минут до истечения
        return (int) $tokenData['token_expires'] < (time() + 300);
    }

    public function getValidToken(string $domain): string
    {
        if ($this->isTokenExpired($domain)) {
            return $this->refreshToken($domain);
        }
        
        $tokenData = $this->tokenRepository->findByDomain($domain);
        return $tokenData['access_token'] ?? throw new Exception('Token not found');
    }
}
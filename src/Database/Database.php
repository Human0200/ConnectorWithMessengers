<?php

declare(strict_types=1);

namespace BitrixTelegram\Database;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=%s',
                    $this->config['host'],
                    $this->config['name'],
                    $this->config['charset']
                );

                self::$instance = new PDO(
                    $dsn,
                    $this->config['user'],
                    $this->config['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                throw new PDOException(
                    'Database connection failed: ' . $e->getMessage(),
                    (int) $e->getCode()
                );
            }
        }

        return self::$instance;
    }

    public static function getInstance(array $config): self
    {
        return new self($config);
    }
}
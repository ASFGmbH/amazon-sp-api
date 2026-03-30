<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

final class ZweipunktPDO
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO(
                'mysql:host=' . $_ENV['ZWEIPUNKT_DB_HOST'] .
                ';dbname=' . $_ENV['ZWEIPUNKT_DB_NAME'] .
                ';charset=utf8mb4',
                $_ENV['ZWEIPUNKT_DB_USER'],
                $_ENV['ZWEIPUNKT_DB_PASS'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }

        return self::$instance;
    }
}
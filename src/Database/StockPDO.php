<?php

namespace App\Database;

use PDO;

class StockPDO
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO(
                "mysql:host=".$_ENV['STOCK_DB_HOST'].";dbname=".$_ENV['STOCK_DB_NAME'].";charset=utf8mb4",
                $_ENV['STOCK_DB_USER'],
                $_ENV['STOCK_DB_PASS'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]
            );
        }

        return self::$instance;
    }
}
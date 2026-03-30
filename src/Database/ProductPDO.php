<?php

namespace App\Database;

use PDO;

class ProductPDO
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO(
                "mysql:host=".$_ENV['PRODUCT_DB_HOST'].";dbname=".$_ENV['PRODUCT_DB_NAME'].";charset=utf8mb4",
                $_ENV['PRODUCT_DB_USER'],
                $_ENV['PRODUCT_DB_PASS'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]
            );
        }

        return self::$instance;
    }
}
<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ProductPlaceholderService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function getColumns(): array
    {
        $stmt = $this->pdo->query("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'object_query_1'
            ORDER BY ORDINAL_POSITION
        ");

        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(
            static fn(mixed $col): string => (string)$col,
            $cols ?: []
        );
    }

    public function getPlaceholders(): array
    {
        $placeholders = [];

        foreach ($this->getColumns() as $column) {
            $placeholders[] = '{{' . $column . '}}';
        }

        return $placeholders;
    }

    public function getSampleRow(): array
    {
        $stmt = $this->pdo->query("
            SELECT *
            FROM object_query_1
            ORDER BY 1 DESC
            LIMIT 1
        ");

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [];
        }

        $normalized = [];

        foreach ($row as $key => $value) {
            if ($value === null) {
                $normalized[(string)$key] = '';
                continue;
            }

            if (is_scalar($value)) {
                $normalized[(string)$key] = (string)$value;
                continue;
            }

            $normalized[(string)$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $normalized;
    }
}
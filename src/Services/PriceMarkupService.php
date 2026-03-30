<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class PriceMarkupService
{
    public function __construct(
        private PDO $appPdo,
        private PDO $productPdo,
        private PDO $zweipunktPdo
    ) {
    }

    public function getDistinctPriceGroups(): array
    {
        $stmt = $this->productPdo->query("
            SELECT DISTINCT priceGroup
            FROM object_query_1
            WHERE priceGroup IS NOT NULL
              AND priceGroup <> ''
            ORDER BY CAST(priceGroup AS UNSIGNED), priceGroup
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(
            static fn(mixed $value): string => (string) $value,
            $rows ?: []
        );
    }

    public function getMarkupMap(): array
    {
        $stmt = $this->appPdo->query("
            SELECT price_group, markup_type, markup_value
            FROM price_markup
        ");

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['price_group']] = [
                'price_group'  => (string) $row['price_group'],
                'markup_type'  => (string) $row['markup_type'],
                'markup_value' => (float) $row['markup_value'],
            ];
        }

        return $result;
    }

    public function saveMany(array $rows): void
    {
        $sql = "
            INSERT INTO price_markup (price_group, markup_type, markup_value)
            VALUES (:price_group, :markup_type, :markup_value)
            ON DUPLICATE KEY UPDATE
                markup_type = VALUES(markup_type),
                markup_value = VALUES(markup_value),
                updated_at = CURRENT_TIMESTAMP
        ";

        $stmt = $this->appPdo->prepare($sql);

        $this->appPdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                $stmt->execute([
                    'price_group'  => (string) $row['price_group'],
                    'markup_type'  => $row['markup_type'] === 'percent' ? 'percent' : 'absolute',
                    'markup_value' => (float) $row['markup_value'],
                ]);
            }

            $this->appPdo->commit();
        } catch (Throwable $e) {
            if ($this->appPdo->inTransaction()) {
                $this->appPdo->rollBack();
            }

            throw $e;
        }
    }

    public function getZweipunktPricesForGroup(string $priceGroup): array
    {
        $discountName = 'priceGroup_' . $priceGroup;
        $pseudoName   = 'priceGroup_' . $priceGroup . '_pseudo';

        $stmt = $this->zweipunktPdo->prepare("
            SELECT name, value
            FROM zweipunkt_setting
            WHERE name = :discount_name
               OR name = :pseudo_name
        ");

        $stmt->execute([
            'discount_name' => $discountName,
            'pseudo_name'   => $pseudoName,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $discount = null;
        $pseudo = null;

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $value = $this->normalizePriceValue($row['value'] ?? null);

            if ($name === strtolower($discountName)) {
                $discount = $value;
            }

            if ($name === strtolower($pseudoName)) {
                $pseudo = $value;
            }
        }

        return [
            'discount_price' => $discount,
            'pseudo_price'   => $pseudo,
        ];
    }

    public function applyMarkup(?float $basePrice, string $markupType, float $markupValue): ?float
    {
        if ($basePrice === null) {
            return null;
        }

        $result = $basePrice;

        if ($markupValue != 0.0) {
            if ($markupType === 'percent') {
                $result += $basePrice * ($markupValue / 100);
            } else {
                $result += $markupValue;
            }
        }

        return round($result, 2);
    }

    private function normalizePriceValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(['€', ' '], '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
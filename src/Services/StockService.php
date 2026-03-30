<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\StockPDO;
use App\Helpers\VariantParser;
use PDO;

final class StockService
{
    public function getStructuredStock(string $model): array
    {
        $pdo = StockPDO::get();

        $stmt = $pdo->prepare(
            "SELECT product_code, quantity
             FROM products
             WHERE product_code LIKE :model"
        );

        $stmt->execute([
            'model' => $model . '%',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'total' => 0,
            'damen' => [],
            'herren' => [],
        ];

        foreach ($rows as $row) {
            $productCode = (string) ($row['product_code'] ?? '');
            $qty = (int) ($row['quantity'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $parsed = VariantParser::parse($productCode);
            if ($parsed === null) {
                continue;
            }

            $size = $parsed['size'];
            $result['total'] += $qty;

            if ($parsed['gender'] === 'Damen') {
                if (!isset($result['damen'][$size])) {
                    $result['damen'][$size] = 0;
                }
                $result['damen'][$size] += $qty;
            } else {
                if (!isset($result['herren'][$size])) {
                    $result['herren'][$size] = 0;
                }
                $result['herren'][$size] += $qty;
            }
        }

        ksort($result['damen'], SORT_NUMERIC);
        ksort($result['herren'], SORT_NUMERIC);

        return $result;
    }

    public function calculateAmazonChildQuantity(array $stock, string $damenSize, string $herrenSize, int $minstock = 0): int
    {
        $damenQty = (int) (($stock['damen'][$damenSize] ?? 0));
        $herrenQty = (int) (($stock['herren'][$herrenSize] ?? 0));

        if ($damenQty <= 0 || $herrenQty <= 0) {
            return 0;
        }

        $quantity = min($damenQty, $herrenQty);
        $minstock = max(0, $minstock);

        return max(0, $quantity - $minstock);
    }

    public function getVariantBadges(string $model): array
    {
        $stock = $this->getStructuredStock($model);
        $variants = [];

        foreach ($stock['damen'] as $size => $qty) {
            $variants[] = [
                'label' => 'D' . $size,
                'qty'   => (int) $qty,
            ];
        }

        foreach ($stock['herren'] as $size => $qty) {
            $variants[] = [
                'label' => 'H' . $size,
                'qty'   => (int) $qty,
            ];
        }

        usort(
            $variants,
            static fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label'])
        );

        return $variants;
    }
}

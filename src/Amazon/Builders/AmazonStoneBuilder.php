<?php
declare(strict_types=1);

namespace App\Amazon\Builders;

use App\Helpers\StoneRowHelper;
use RuntimeException;

final class AmazonStoneBuilder
{
    /**
     * Baut das Amazon-"stones"-Array passend zum gecachten RING-Schema.
     * Die Steinanzahl bezieht sich bewusst auf den Damenring.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buildFromProductRow(array $productRow, float $ringSize): array
    {
        $stoneRow = StoneRowHelper::calculateFromProductRow($productRow, $ringSize);
        if ($stoneRow === null || $stoneRow['stone_count'] <= 0) {
            return [];
        }

        $marketplaceId = self::getMarketplaceId();

        return [[
            'marketplace_id' => $marketplaceId,
            'id' => 1,
            'type' => [
                'language_tag' => 'de_DE',
                'value' => self::mapStoneType((string)($productRow['customfield_asf_default_stone'] ?? 'Zirkonia')),
            ],
            'number_of_stones' => $stoneRow['stone_count'],
            'creation_method' => [
                'language_tag' => 'de_DE',
                'value' => self::mapCreationMethod((string)($productRow['customfield_asf_default_stone'] ?? 'Zirkonia')),
            ],
            'treatment_method' => [
                'language_tag' => 'de_DE',
                'value' => 'Nicht behandelt',
            ],
            'color' => [
                'language_tag' => 'de_DE',
                'value' => self::mapStoneColor((string)($productRow['customfield_asf_stone_colors'] ?? 'weiß;')),
            ],
            'cut' => [
                'language_tag' => 'de_DE',
                'value' => self::mapStoneCut((string)($productRow['customfield_asf_ground'] ?? 'Brillant')),
            ],
            'shape' => [
                'language_tag' => 'de_DE',
                'value' => self::mapStoneShape((string)($productRow['customfield_asf_ground'] ?? 'Brillant')),
            ],
        ]];
    }

    private static function getMarketplaceId(): string
    {
        $marketplaceId = trim((string)($_ENV['AMAZON_MARKETPLACE_ID'] ?? getenv('AMAZON_MARKETPLACE_ID') ?: ''));
        if ($marketplaceId === '') {
            throw new RuntimeException('AMAZON_MARKETPLACE_ID ist nicht in der Umgebung gesetzt.');
        }

        return $marketplaceId;
    }

    private static function mapStoneType(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            'zirkonia', 'cubic zirconia' => 'cubic_zirconia',
            'diamant', 'diamond' => 'Diamant',
            default => 'cubic_zirconia',
        };
    }

    private static function mapCreationMethod(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            'diamant', 'diamond' => 'Natürlich',
            'zirkonia', 'cubic zirconia' => 'Simuliert',
            default => 'Simuliert',
        };
    }

    private static function mapStoneColor(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = rtrim($value, ';');
        if (str_contains($value, ';')) {
            $value = trim(explode(';', $value)[0]);
        }

        return match ($value) {
            'weiß', 'weiss', 'white' => 'Weiß',
            'schwarz', 'black' => 'Schwarz',
            'blau', 'blue' => 'Blau',
            'rot', 'red' => 'Rot',
            'grün', 'gruen', 'green' => 'Grün',
            'gelb', 'yellow' => 'Gelb',
            'rosa', 'pink' => 'Lila',
            default => 'Weiß',
        };
    }

    private static function mapStoneCut(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            'brillant', 'brilliant' => 'Sehr gut',
            default => 'Sehr gut',
        };
    }

    private static function mapStoneShape(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            'brillant', 'brilliant', 'rund', 'round' => 'Runder Brillant',
            'oval' => 'Oval',
            'emerald' => 'Smaragd',
            default => 'Runder Brillant',
        };
    }
}

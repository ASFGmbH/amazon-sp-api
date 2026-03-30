<?php
declare(strict_types=1);

namespace App\Helpers;

use InvalidArgumentException;
use RuntimeException;

final class StoneRowHelper
{
    /**
     * Stein-Durchmesser in mm je ct-Wert.
     *
     * Keys bewusst als String, damit wir stabil nach normierten
     * Carat-Werten suchen können.
     *
     * @var array<string, float>
     */
    private const STONE_DIMENSIONS = [
        '0.005' => 1.0,
        '0.01'  => 1.3,
        '0.015' => 1.5,
        '0.02'  => 1.7,
        '0.03'  => 1.9,
        '0.035' => 2.1,
        '0.04'  => 2.2,
        '0.05'  => 2.3,
        '0.06'  => 2.6,
        '0.07'  => 2.7,
        '0.08'  => 2.8,
        '0.09'  => 2.9,
        '0.1'   => 3.0,
        '0.11'  => 3.1,
        '0.12'  => 3.2,
        '0.13'  => 3.3,
        '0.14'  => 3.4,
        '0.15'  => 3.5,
        '0.16'  => 3.55,
        '0.17'  => 3.6,
        '0.18'  => 3.7,
        '0.19'  => 3.75,
        '0.2'   => 3.8,
        '0.25'  => 4.1,
        '0.3'   => 4.4,
        '0.35'  => 4.6,
        '0.37'  => 4.0,
        '0.4'   => 4.8,
        '0.45'  => 5.0,
        '0.46'  => 4.5,
        '0.5'   => 5.2,
        '0.55'  => 5.35,
        '0.6'   => 5.5,
        '0.65'  => 5.65,
        '0.7'   => 5.8,
        '0.75'  => 5.9,
        '0.8'   => 6.0,
        '0.85'  => 6.2,
        '0.9'   => 6.3,
        '0.95'  => 6.4,
        '1'     => 6.5,
        '1.05'  => 5.5,
        '1.35'  => 6.0,
        '1.65'  => 6.5,
        '1.9'   => 7.0,
        '2.2'   => 7.5,
        '2.7'   => 8.0,
        '7'     => 1.1,
    ];

    private const TYPE_STEINKRANZ = 'steinkranz';
    private const TYPE_HALBKRANZ  = 'halbkranz';

    /**
     * Liest aus customfield_asf_stones den Steinreihen-Typ und ct-Wert.
     *
     * Unterstützt z. B.:
     * - steinkranzx0.005
     * - halbkranzx0.01
     * - 4x0.005ct.|steinkranzx0.005
     *
     * Gibt null zurück, wenn keine Steinreihe vorhanden ist.
     *
     * @return array{type:string, carat:float}|null
     */
    public static function parseStoneRow(string $stoneString): ?array
    {
        $parts = array_filter(array_map('trim', explode('|', $stoneString)));

        foreach ($parts as $part) {
            $chunks = explode('x', mb_strtolower($part));
            if (count($chunks) !== 2) {
                continue;
            }

            $type = trim($chunks[0]);
            $rawCarat = trim($chunks[1]);

            if ($type !== self::TYPE_STEINKRANZ && $type !== self::TYPE_HALBKRANZ) {
                continue;
            }

            $rawCarat = str_replace(',', '.', $rawCarat);
            $rawCarat = str_ireplace('ct.', '', $rawCarat);
            $rawCarat = trim($rawCarat);

            if ($rawCarat === '' || !is_numeric($rawCarat)) {
                throw new InvalidArgumentException(
                    'Steinreihen-Carat konnte nicht gelesen werden: ' . $part
                );
            }

            return [
                'type'  => $type,
                'carat' => (float) $rawCarat,
            ];
        }

        return null;
    }

    /**
     * Berechnet die Anzahl der Steine einer Steinreihe bzw. mehrerer Reihen
     * anhand des Außenumfangs.
     *
     * Ringgröße wird als Innenumfang in mm erwartet, z. B. 56.
     */
    public static function calculateStoneRowCount(
        float $ringSize,
        float $ringStrength,
        float $stoneCarat,
        float $rowGap,
        float $numberOfRows
    ): int {
        if ($ringSize <= 0) {
            throw new InvalidArgumentException('ringSize muss > 0 sein.');
        }

        if ($ringStrength < 0) {
            throw new InvalidArgumentException('ringStrength darf nicht < 0 sein.');
        }

        if ($stoneCarat <= 0) {
            throw new InvalidArgumentException('stoneCarat muss > 0 sein.');
        }

        if ($rowGap < 0) {
            throw new InvalidArgumentException('rowGap darf nicht < 0 sein.');
        }

        if ($numberOfRows <= 0) {
            return 0;
        }

        $stoneDiameter = self::getStoneDiameter($stoneCarat);
        $outerPerimeter = self::calculateOuterPerimeter($ringSize, $ringStrength);

        $oneRow = (int) floor($outerPerimeter / ($stoneDiameter + $rowGap));
        $total  = (int) floor($oneRow * $numberOfRows);

        return max(0, $total);
    }

    /**
     * Komfortmethode direkt aus einer ProductPDO-Row.
     *
     * Erwartet:
     * - customfield_asf_stones
     * - customfield_asf_stoneRows
     * - customfield_asf_stoneRowMargin
     * - customfield_asf_default_strength
     *
     * Ringgröße ist der Innenumfang in mm.
     */
    public static function calculateFromProductRow(array $productRow, float $ringSize): ?array
    {
        $stoneString = trim((string) ($productRow['customfield_asf_stones'] ?? ''));
        if ($stoneString === '') {
            return null;
        }

        $parsed = self::parseStoneRow($stoneString);
        if ($parsed === null) {
            return null;
        }

        $numberOfRows = (float) ($productRow['customfield_asf_stoneRows'] ?? 0);
        $rowGap       = (float) ($productRow['customfield_asf_stoneRowMargin'] ?? 0);
        $ringStrength = (float) ($productRow['customfield_asf_default_strength'] ?? 0);

        $count = self::calculateStoneRowCount(
            ringSize: $ringSize,
            ringStrength: $ringStrength,
            stoneCarat: $parsed['carat'],
            rowGap: $rowGap,
            numberOfRows: $numberOfRows
        );

        return [
            'type'            => $parsed['type'],
            'carat'           => $parsed['carat'],
            'rows'            => $numberOfRows,
            'gap'             => $rowGap,
            'ring_size'       => $ringSize,
            'ring_strength'   => $ringStrength,
            'outer_perimeter' => self::calculateOuterPerimeter($ringSize, $ringStrength),
            'stone_diameter'  => self::getStoneDiameter($parsed['carat']),
            'stone_count'     => $count,
        ];
    }

    public static function calculateOuterPerimeter(float $ringSize, float $ringStrength): float
    {
        $innerDiameter = $ringSize / pi();
        $outerDiameter = $innerDiameter + (2 * $ringStrength);

        return $outerDiameter * pi();
    }

    public static function getStoneDiameter(float $stoneCarat): float
    {
        $key = self::normalizeCaratKey($stoneCarat);

        if (!array_key_exists($key, self::STONE_DIMENSIONS)) {
            throw new RuntimeException(
                'Für den Steinwert gibt es keinen hinterlegten Durchmesser: ' . $key
            );
        }

        return self::STONE_DIMENSIONS[$key];
    }

    private static function normalizeCaratKey(float $carat): string
    {
        $normalized = rtrim(rtrim(number_format($carat, 3, '.', ''), '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }
}
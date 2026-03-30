<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AmazonPriceService
{
    private PDO $pdo;

    /** @var array<string, string|null> */
    private array $settingsCache = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $markupCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Erwartete setting_keys:
     *
     * - amazon_price.tax_rate
     *      Beispiel: 1.19
     *
     * - amazon_price.round_to
     *      Beispiel: 5
     *
     * - amazon_price.min_price
     *      Beispiel: 49.95
     *
     * - amazon_price.default_price_group
     *      Beispiel: ring_default
     */

    public function calculateListingPrice(
        float $basePrice,
        ?string $priceGroup = null,
        bool $basePriceIncludesTax = true
    ): float {
        $result = $this->calculatePriceBreakdown($basePrice, $priceGroup, $basePriceIncludesTax);
        return $result['final_gross'];
    }

    /**
     * @return array{
     *     base_input: float,
     *     base_net: float,
     *     base_gross: float,
     *     markup_type: string,
     *     markup_value: float,
     *     markup_amount_net: float,
     *     price_after_markup_net: float,
     *     price_after_markup_gross: float,
     *     rounded_gross: float,
     *     final_gross: float,
     *     tax_rate: float,
     *     round_to: float,
     *     min_price: float,
     *     price_group: string
     * }
     */
    public function calculatePriceBreakdown(
        float $basePrice,
        ?string $priceGroup = null,
        bool $basePriceIncludesTax = true
    ): array {
        if ($basePrice < 0) {
            throw new RuntimeException('basePrice darf nicht negativ sein.');
        }

        $taxRate   = $this->getSettingFloat('amazon_price.tax_rate', 1.19);
        $roundTo   = $this->getSettingFloat('amazon_price.round_to', 5.0);
        $minPrice  = $this->getSettingFloat('amazon_price.min_price', 0.0);
        $groupName = $priceGroup !== null && trim($priceGroup) !== ''
            ? trim($priceGroup)
            : $this->getSettingString('amazon_price.default_price_group', 'ring_default');

        $markup = $this->getMarkupRow($groupName);

        $markupType  = 'absolute';
        $markupValue = 0.0;

        if (is_array($markup)) {
            $markupType  = (string)($markup['markup_type'] ?? 'absolute');
            $markupValue = (float)($markup['markup_value'] ?? 0.0);
        }

        $baseNet = $basePriceIncludesTax
            ? $this->divideSafe($basePrice, $taxRate)
            : $basePrice;

        $baseGross = $basePriceIncludesTax
            ? $basePrice
            : $basePrice * $taxRate;

        $markupAmountNet = $this->calculateMarkupAmountNet($baseNet, $markupType, $markupValue);

        $priceAfterMarkupNet   = $baseNet + $markupAmountNet;
        $priceAfterMarkupGross = $priceAfterMarkupNet * $taxRate;

        $roundedGross = $this->roundUpToStep($priceAfterMarkupGross, $roundTo);
        $finalGross   = max($roundedGross, $minPrice);

        return [
            'base_input' => round($basePrice, 2),
            'base_net' => round($baseNet, 2),
            'base_gross' => round($baseGross, 2),
            'markup_type' => $markupType,
            'markup_value' => round($markupValue, 2),
            'markup_amount_net' => round($markupAmountNet, 2),
            'price_after_markup_net' => round($priceAfterMarkupNet, 2),
            'price_after_markup_gross' => round($priceAfterMarkupGross, 2),
            'rounded_gross' => round($roundedGross, 2),
            'final_gross' => round($finalGross, 2),
            'tax_rate' => $taxRate,
            'round_to' => $roundTo,
            'min_price' => $minPrice,
            'price_group' => $groupName,
        ];
    }

    public function getSettingString(string $key, string $default = ''): string
    {
        $value = $this->getSettingRaw($key);

        if ($value === null || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    public function getSettingFloat(string $key, float $default = 0.0): float
    {
        $value = $this->getSettingRaw($key);

        if ($value === null) {
            return $default;
        }

        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return (float)$value;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMarkupRow(string $priceGroup): ?array
    {
        $priceGroup = trim($priceGroup);
        if ($priceGroup === '') {
            return null;
        }

        if (array_key_exists($priceGroup, $this->markupCache)) {
            return $this->markupCache[$priceGroup];
        }

        $stmt = $this->pdo->prepare(
            'SELECT price_group, markup_type, markup_value
             FROM price_markup
             WHERE price_group = :price_group
             LIMIT 1'
        );
        $stmt->execute([
            'price_group' => $priceGroup,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->markupCache[$priceGroup] = is_array($row) ? $row : null;

        return $this->markupCache[$priceGroup];
    }

    /**
     * Einfache Heuristik für den Start.
     * Kann später durch echte Produktlogik ersetzt werden.
     */
    public function detectPriceGroupFromNormalizedData(?array $normalized): string
    {
        $default = $this->getSettingString('amazon_price.default_price_group', 'ring_default');

        if (!is_array($normalized)) {
            return $default;
        }

        $materials = $normalized['materials'] ?? [];
        if (!is_array($materials) || $materials === []) {
            return $default;
        }

        $materialsLower = array_map(
            static fn(mixed $v): string => mb_strtolower(trim((string)$v)),
            $materials
        );

        if (in_array('carbon', $materialsLower, true)) {
            return 'ring_carbon';
        }

        if (in_array('holz', $materialsLower, true)) {
            return 'ring_holz';
        }

        if (in_array('titan', $materialsLower, true)) {
            return 'ring_titan';
        }

        if (in_array('edelstahl', $materialsLower, true)) {
            return 'ring_edelstahl';
        }

        return $default;
    }

    private function getSettingRaw(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, $this->settingsCache)) {
            return $this->settingsCache[$key];
        }

        $stmt = $this->pdo->prepare(
            'SELECT setting_value
             FROM settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );
        $stmt->execute([
            'setting_key' => $key,
        ]);

        $value = $stmt->fetchColumn();
        $this->settingsCache[$key] = $value !== false ? (string)$value : null;

        return $this->settingsCache[$key];
    }

    private function calculateMarkupAmountNet(float $baseNet, string $markupType, float $markupValue): float
    {
        return match ($markupType) {
            'percent' => $baseNet * ($markupValue / 100),
            'absolute' => $markupValue,
            default => 0.0,
        };
    }

    private function roundUpToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return round($value, 2);
        }

        return ceil($value / $step) * $step;
    }

    private function divideSafe(float $value, float $divisor): float
    {
        if ($divisor <= 0.0) {
            throw new RuntimeException('Steuerfaktor muss größer als 0 sein.');
        }

        return $value / $divisor;
    }
}
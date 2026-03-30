<?php
declare(strict_types=1);

namespace App\Helpers;

final class VariantParser
{
    /**
     * Erwartet product_code mit echter Größenkennung wie:
     * - C001D48
     * - C001H58
     * - MODEL-XYZ-D54
     * - MODEL_H60
     *
     * @return array{gender:string,size:string}|null
     */
    public static function parse(string $productCode): ?array
    {
        $productCode = trim($productCode);
        if ($productCode === '') {
            return null;
        }

        if (!preg_match_all('/([DH])(\d{2,3})/i', $productCode, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $last = end($matches);
        if ($last === false) {
            return null;
        }

        $genderCode = strtoupper((string) $last[1]);
        $sizeRaw = (string) $last[2];
        $size = ltrim($sizeRaw, '0');
        if ($size === '') {
            $size = '0';
        }

        return [
            'gender' => $genderCode === 'D' ? 'Damen' : 'Herren',
            'size'   => $size,
        ];
    }
}
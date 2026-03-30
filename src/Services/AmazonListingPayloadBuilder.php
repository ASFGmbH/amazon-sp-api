<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class AmazonListingPayloadBuilder
{
    private string $sellerSku = '';
    private string $productType = 'RING';
    private string $requirements = 'LISTING';
    private string $marketplaceId = '';
    private string $languageTag = 'de_DE';
    private string $currency = 'EUR';

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $attributes = [];

    public function setSellerSku(string $sellerSku): self
    {
        $sellerSku = trim($sellerSku);
        if ($sellerSku === '') {
            throw new InvalidArgumentException('sellerSku darf nicht leer sein.');
        }

        $this->sellerSku = $sellerSku;
        return $this;
    }

    public function setProductType(string $productType): self
    {
        $productType = trim($productType);
        if ($productType === '') {
            throw new InvalidArgumentException('productType darf nicht leer sein.');
        }

        $this->productType = strtoupper($productType);
        return $this;
    }

    public function setRequirements(string $requirements): self
    {
        $requirements = trim($requirements);
        if ($requirements === '') {
            throw new InvalidArgumentException('requirements darf nicht leer sein.');
        }

        $this->requirements = strtoupper($requirements);
        return $this;
    }

    public function setMarketplaceId(string $marketplaceId): self
    {
        $marketplaceId = trim($marketplaceId);
        if ($marketplaceId === '') {
            throw new InvalidArgumentException('marketplaceId darf nicht leer sein.');
        }

        $this->marketplaceId = $marketplaceId;
        return $this;
    }

    public function setLanguageTag(string $languageTag): self
    {
        $languageTag = trim($languageTag);
        if ($languageTag === '') {
            throw new InvalidArgumentException('languageTag darf nicht leer sein.');
        }

        $this->languageTag = $languageTag;
        return $this;
    }

    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw new InvalidArgumentException('currency darf nicht leer sein.');
        }

        $this->currency = $currency;
        return $this;
    }

    public function setItemName(string $value): self
    {
        return $this->setLocalizedValueAttribute('item_name', $value);
    }

    public function setBrand(string $value): self
    {
        return $this->setSimpleValueAttribute('brand', $value);
    }

    public function setProductDescription(string $value): self
    {
        return $this->setLocalizedValueAttribute('product_description', $value);
    }

    /**
     * @param array<int, string> $bulletPoints
     */
    public function setBulletPoints(array $bulletPoints): self
    {
        $this->attributes['bullet_point'] = [];

        foreach ($bulletPoints as $bulletPoint) {
            $this->addBulletPoint($bulletPoint);
        }

        return $this;
    }

    public function addBulletPoint(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            return $this;
        }

        $this->attributes['bullet_point'][] = [
            'value' => $value,
            'marketplace_id' => $this->requireMarketplaceId(),
            'language_tag' => $this->languageTag,
        ];

        return $this;
    }

    public function setPrice(float $valueWithTax): self
    {
        $this->attributes['purchasable_offer'] = [[
            'marketplace_id' => $this->requireMarketplaceId(),
            'currency' => $this->currency,
            'our_price' => [[
                'schedule' => [[
                    'value_with_tax' => $this->normalizeMoney($valueWithTax),
                ]],
            ]],
        ]];

        return $this;
    }

    public function setListPrice(float $valueWithTax): self
    {
        $this->attributes['list_price'] = [[
            'marketplace_id' => $this->requireMarketplaceId(),
            'currency' => $this->currency,
            'value_with_tax' => $this->normalizeMoney($valueWithTax),
        ]];

        return $this;
    }

    public function setQuantity(int $quantity, string $fulfillmentChannelCode = 'DEFAULT'): self
    {
        $this->attributes['fulfillment_availability'] = [[
            'marketplace_id' => $this->requireMarketplaceId(),
            'fulfillment_channel_code' => $fulfillmentChannelCode,
            'quantity' => max(0, $quantity),
        ]];

        return $this;
    }

    public function setPartNumber(string $value): self
    {
        return $this->setSimpleValueAttribute('part_number', $value);
    }

    public function setSupplierDeclaredDgHzRegulation(string $value): self
    {
        return $this->setSimpleValueAttribute('supplier_declared_dg_hz_regulation', $value);
    }

    public function setJewelryMaterialCategorization(string $value): self
    {
        return $this->setSimpleValueAttribute('jewelry_material_categorization', $value);
    }

    public function setDepartment(string $value): self
    {
        return $this->setSimpleValueAttribute('department', $value);
    }

    public function setColor(string $value): self
    {
        return $this->setSimpleValueAttribute('color', $value);
    }

    public function setBatteriesRequired(bool $value): self
    {
        $this->attributes['batteries_required'] = [[
            'value' => $value,
            'marketplace_id' => $this->requireMarketplaceId(),
        ]];

        return $this;
    }

    public function setSupplierDeclaredHasProductIdentifierExemption(bool $value): self
    {
        $this->attributes['supplier_declared_has_product_identifier_exemption'] = [[
            'value' => $value,
            'marketplace_id' => $this->requireMarketplaceId(),
        ]];

        return $this;
    }

    public function setExternalProductId(string $value, string $type = 'EAN'): self
    {
        $value = trim($value);
        $type = strtoupper(trim($type));

        if ($value === '') {
            throw new InvalidArgumentException('externally_assigned_product_identifier darf nicht leer sein.');
        }

        if (!in_array($type, ['EAN', 'UPC', 'GTIN'], true)) {
            throw new InvalidArgumentException('external product id type muss EAN, UPC oder GTIN sein.');
        }

        $this->attributes['externally_assigned_product_identifier'] = [[
            'value' => $value,
            'type' => $type,
            'marketplace_id' => $this->requireMarketplaceId(),
        ]];

        return $this;
    }

    public function setGemType(string $value): self
    {
        return $this->setSimpleValueAttribute('gem_type', $value);
    }

    public function setCountryOfOrigin(string $value): self
    {
        return $this->setSimpleValueAttribute('country_of_origin', strtoupper(trim($value)));
    }

    public function setMaterial(string $value): self
    {
        return $this->setSimpleValueAttribute('material', $value);
    }

    /**
     * @param array<int, string> $values
     */
    public function setMetalTypes(array $values): self
    {
        return $this->setLocalizedArrayAttribute('metal_type', $values);
    }

    /**
     * @param array<int, string> $values
     */
    public function setOccasionTypes(array $values): self
    {
        return $this->setLocalizedArrayAttribute('occasion_type', $values);
    }

    public function setStyle(string $value): self
    {
        return $this->setLocalizedArrayAttribute('style', [$value]);
    }

    public function setRingFormType(string $value): self
    {
        return $this->setLocalizedArrayAttribute('ring_form_type', [$value]);
    }

    public function setGpsrManufacturerReference(string $value): self
    {
        return $this->setSimpleValueAttribute('gpsr_manufacturer_reference', $value);
    }

    public function setGpsrSafetyAttestation(bool $value): self
    {
        $this->attributes['gpsr_safety_attestation'] = [[
            'value' => $value,
            'marketplace_id' => $this->requireMarketplaceId(),
        ]];

        return $this;
    }

    public function setIsResizable(bool $value): self
    {
        $this->attributes['is_resizable'] = [[
            'value' => $value,
            'marketplace_id' => $this->requireMarketplaceId(),
        ]];

        return $this;
    }

    public function setMerchantSuggestedAsin(string $value): self
    {
        return $this->setSimpleValueAttribute('merchant_suggested_asin', strtoupper(trim($value)));
    }

    public function setRecommendedBrowseNode(int $nodeId): self
    {
        $this->attributes['recommended_browse_nodes'] = [[
            'marketplace_id' => $this->requireMarketplaceId(),
            'value' => $nodeId,
        ]];

        return $this;
    }

    public function setRingSize(string $ringSize): self
    {
        $ringSize = trim($ringSize);
        if ($ringSize === '') {
            throw new InvalidArgumentException('ringSize darf nicht leer sein.');
        }

        $this->attributes['ring'] = [[
            'marketplace_id' => $this->requireMarketplaceId(),
            'size' => [[
                'value' => $ringSize,
            ]],
        ]];

        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function setRawAttribute(string $attributeName, array $entries): self
    {
        $attributeName = trim($attributeName);
        if ($attributeName === '') {
            throw new InvalidArgumentException('attributeName darf nicht leer sein.');
        }

        $this->attributes[$attributeName] = $entries;
        return $this;
    }

    public function removeAttribute(string $attributeName): self
    {
        unset($this->attributes[$attributeName]);
        return $this;
    }

    /**
     * @return array{
     *   sellerSku:string,
     *   productType:string,
     *   requirements:string,
     *   attributes:array<string, array<int, array<string, mixed>>>
     * }
     */
    public function build(): array
    {
        if ($this->sellerSku === '') {
            throw new InvalidArgumentException('sellerSku fehlt.');
        }

        if ($this->marketplaceId === '') {
            throw new InvalidArgumentException('marketplaceId fehlt.');
        }

        return [
            'sellerSku' => $this->sellerSku,
            'productType' => $this->productType,
            'requirements' => $this->requirements,
            'attributes' => $this->attributes,
        ];
    }

    private function setSimpleValueAttribute(string $attributeName, string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s darf nicht leer sein.', $attributeName));
        }

        $this->attributes[$attributeName] = [[
            'value' => $value,
            'marketplace_id' => $this->requireMarketplaceId(),
        ]];

        return $this;
    }

    private function setLocalizedValueAttribute(string $attributeName, string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s darf nicht leer sein.', $attributeName));
        }

        $this->attributes[$attributeName] = [[
            'value' => $value,
            'marketplace_id' => $this->requireMarketplaceId(),
            'language_tag' => $this->languageTag,
        ]];

        return $this;
    }

    /**
     * @param array<int, string> $values
     */
    private function setLocalizedArrayAttribute(string $attributeName, array $values): self
    {
        $entries = [];
        $seen = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $normalized = mb_strtolower($value);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $entries[] = [
                'value' => $value,
                'marketplace_id' => $this->requireMarketplaceId(),
                'language_tag' => $this->languageTag,
            ];
        }

        if ($entries === []) {
            throw new InvalidArgumentException(sprintf('%s darf nicht leer sein.', $attributeName));
        }

        $this->attributes[$attributeName] = $entries;
        return $this;
    }

    private function requireMarketplaceId(): string
    {
        if ($this->marketplaceId === '') {
            throw new InvalidArgumentException('marketplaceId muss gesetzt werden, bevor Attribute gebaut werden.');
        }

        return $this->marketplaceId;
    }

    private function normalizeMoney(float $value): float
    {
        return round($value, 2);
    }
}

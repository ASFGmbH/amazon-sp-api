<?php
declare(strict_types=1);

namespace App\Services;

use App\Amazon\Builders\AmazonStoneBuilder;
use App\Database\Database;
use App\Database\ProductPDO;
use App\Database\ZweipunktPDO;
use PDO;
use RuntimeException;

final class AmazonPushService
{
    private PDO $appPdo;
    private PDO $productPdo;
    private PDO $zweipunktPdo;

    private StockService $stockService;
    private AmazonService $amazonService;
    private AmazonImageHelper $imageHelper;
    private TemplateService $templateService;
    private SettingsService $settingsService;
    private PriceMarkupService $priceMarkupService;

    public function __construct()
    {
        $this->appPdo = Database::get();
        $this->productPdo = ProductPDO::get();
        $this->zweipunktPdo = ZweipunktPDO::get();

        $this->stockService = new StockService();
        $this->amazonService = new AmazonService();
        $this->imageHelper = new AmazonImageHelper();
        $this->templateService = new TemplateService();
        $this->settingsService = new SettingsService($this->appPdo);
        $this->priceMarkupService = new PriceMarkupService(
            $this->appPdo,
            $this->productPdo,
            $this->zweipunktPdo
        );
    }

    public function pushModel(string $model, bool $dryRun = true): array
    {
        $model = strtoupper(trim($model));
        if ($model === '') {
            return [
                'success' => false,
                'message' => 'Modell fehlt.',
            ];
        }

        $productRow = $this->loadProductRow($model);
        if ($productRow === null) {
            return [
                'success' => false,
                'message' => 'Produktdatensatz für ' . $model . ' nicht gefunden.',
            ];
        }

        $ringColor = $this->getRingColor($model);
        if ($ringColor === null) {
            return [
                'success' => false,
                'message' => 'Für Modell ' . $model . ' ist keine Ringfarbe gepflegt.',
            ];
        }

        $pairs = $this->buildChildPairs($model);
        if ($pairs === []) {
            return [
                'success' => false,
                'message' => 'Keine lagernden Damen/Herren-Kombinationen für ' . $model . ' gefunden.',
            ];
        }

        $settings = $this->getAmazonSettings();
        $parentSku = $model . '-PARENT';

        $parentPayload = $this->buildParentPayload(
            model: $model,
            productRow: $productRow,
            parentSku: $parentSku,
            ringColor: $ringColor,
            settings: $settings
        );

        $childPayloads = [];
        foreach ($pairs as $pair) {
            $childPayloads[] = $this->buildChildPayload(
                model: $model,
                productRow: $productRow,
                parentSku: $parentSku,
                damenSize: $pair['damen_size'],
                herrenSize: $pair['herren_size'],
                quantity: $pair['quantity'],
                ringColor: $ringColor,
                settings: $settings
            );
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'message' => 'Vorschau erfolgreich erzeugt.',
                'model' => $model,
                'parent_sku' => $parentSku,
                'child_count' => count($childPayloads),
                'children_preview' => array_slice($pairs, 0, 10),
                'parent_payload' => $parentPayload,
                'first_child_payload' => $childPayloads[0]['payload'] ?? null,
            ];
        }

        $results = [];

        $parentResponse = $this->amazonService->createOrUpdateListing($parentPayload);
        $results[] = [
            'sku' => $parentSku,
            'type' => 'parent',
            'response' => $parentResponse,
        ];

        foreach ($childPayloads as $childPayload) {
            $childResponse = $this->amazonService->createOrUpdateListing($childPayload['payload']);

            $results[] = [
                'sku' => (string) $childPayload['sku'],
                'type' => 'child',
                'response' => $childResponse,
            ];

            $this->upsertInventoryTrackingRow(
                model: $model,
                parentSku: $parentSku,
                sku: (string) $childPayload['sku'],
                damenSize: (string) $childPayload['damen_size'],
                herrenSize: (string) $childPayload['herren_size'],
                quantity: (int) $childPayload['quantity'],
                response: $childResponse
            );
        }

        return [
            'success' => true,
            'dry_run' => false,
            'message' => 'Upload abgeschlossen.',
            'model' => $model,
            'parent_sku' => $parentSku,
            'child_count' => count($childPayloads),
            'results' => $results,
        ];
    }

    private function loadProductRow(string $model): ?array
    {
        $stmt = $this->productPdo->prepare(
            "SELECT *
             FROM object_query_1
             WHERE customfield_asf_model = :model
             LIMIT 1"
        );

        $stmt->execute([
            'model' => $model,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function getRingColor(string $model): ?string
    {
        $stmt = $this->appPdo->prepare(
            "SELECT amazon_color
             FROM ring_color
             WHERE model = :model
             LIMIT 1"
        );

        $stmt->execute([
            'model' => $model,
        ]);

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int, array{damen_size:string,herren_size:string,quantity:int}>
     */
    private function buildChildPairs(string $model): array
    {
        $stock = $this->stockService->getStructuredStock($model);
        $minstock = $this->settingsService->getAmazonInventoryMinstock();

        $damenSizes = array_keys($stock['damen'] ?? []);
        $herrenSizes = array_keys($stock['herren'] ?? []);

        $damenSizes = array_values(array_filter(
            array_unique(array_map('strval', $damenSizes)),
            fn(string $size): bool => ctype_digit($size) && (int) ($stock['damen'][$size] ?? 0) > 0
        ));

        $herrenSizes = array_values(array_filter(
            array_unique(array_map('strval', $herrenSizes)),
            fn(string $size): bool => ctype_digit($size) && (int) ($stock['herren'][$size] ?? 0) > 0
        ));

        $pairs = [];

        foreach ($damenSizes as $dSize) {
            $dQty = (int) ($stock['damen'][$dSize] ?? 0);
            if ($dQty <= 0) {
                continue;
            }

            foreach ($herrenSizes as $hSize) {
                $hQty = (int) ($stock['herren'][$hSize] ?? 0);
                if ($hQty <= 0) {
                    continue;
                }

                $qty = $this->stockService->calculateAmazonChildQuantity($stock, $dSize, $hSize, $minstock);
                if ($qty <= 0) {
                    continue;
                }

                $pairs[] = [
                    'damen_size' => $dSize,
                    'herren_size' => $hSize,
                    'quantity' => $qty,
                ];
            }
        }

        usort(
            $pairs,
            static fn(array $a, array $b): int =>
                [(int) $a['damen_size'], (int) $a['herren_size']]
                <=>
                [(int) $b['damen_size'], (int) $b['herren_size']]
        );

        return $pairs;
    }

    private function buildParentPayload(
        string $model,
        array $productRow,
        string $parentSku,
        string $ringColor,
        array $settings
    ): array {
        $builder = $this->createBaseBuilder($parentSku);

        $builder->setRawAttribute('parentage_level', [[
            'value' => 'parent',
            'marketplace_id' => $this->getMarketplaceId(),
        ]]);

        $builder->setRawAttribute('variation_theme', [[
            'name' => 'RING_SIZE',
            'marketplace_id' => $this->getMarketplaceId(),
        ]]);

        $this->applyCommonAttributes(
            builder: $builder,
            model: $model,
            productRow: $productRow,
            ringColor: $ringColor,
            settings: $settings,
            isParent: true,
            damenSize: null,
            herrenSize: null,
            quantity: null
        );

        return $builder->build();
    }

    /**
     * @return array{sku:string,payload:array<string,mixed>,damen_size:string,herren_size:string,quantity:int}
     */
    private function buildChildPayload(
        string $model,
        array $productRow,
        string $parentSku,
        string $damenSize,
        string $herrenSize,
        int $quantity,
        string $ringColor,
        array $settings
    ): array {
        $childSku = sprintf('%s-D%s-H%s', $model, $damenSize, $herrenSize);

        $builder = $this->createBaseBuilder($childSku);

        $builder->setRawAttribute('parentage_level', [[
            'value' => 'child',
            'marketplace_id' => $this->getMarketplaceId(),
        ]]);

        $builder->setRawAttribute('child_parent_sku_relationship', [[
            'child_relationship_type' => 'variation',
            'parent_sku' => $parentSku,
            'marketplace_id' => $this->getMarketplaceId(),
        ]]);

        $builder->setRawAttribute('variation_theme', [[
            'name' => 'RING_SIZE',
            'marketplace_id' => $this->getMarketplaceId(),
        ]]);

        $this->applyCommonAttributes(
            builder: $builder,
            model: $model,
            productRow: $productRow,
            ringColor: $ringColor,
            settings: $settings,
            isParent: false,
            damenSize: $damenSize,
            herrenSize: $herrenSize,
            quantity: $quantity
        );

        return [
            'sku' => $childSku,
            'payload' => $builder->build(),
            'damen_size' => $damenSize,
            'herren_size' => $herrenSize,
            'quantity' => $quantity,
        ];
    }

    private function applyCommonAttributes(
        AmazonListingPayloadBuilder $builder,
        string $model,
        array $productRow,
        string $ringColor,
        array $settings,
        bool $isParent,
        ?string $damenSize,
        ?string $herrenSize,
        ?int $quantity
    ): void {
        $marketplaceId = $this->getMarketplaceId();
        $languageTag = $this->getLanguageTag();

        foreach ($this->imageHelper->buildImageAttributes($model, $marketplaceId) as $attr => $entries) {
            $builder->setRawAttribute($attr, $entries);
        }

        $title = $this->buildTitle($productRow, $damenSize, $herrenSize, $settings);
        if ($title !== '') {
            $builder->setItemName($title);
        }

        $description = $this->buildDescription($productRow, $damenSize, $herrenSize, $settings);
        if ($description !== '') {
            $builder->setProductDescription($description);
        }

        $bulletPoints = $this->buildBulletPoints($productRow, $damenSize, $herrenSize, $settings);
        if ($bulletPoints !== []) {
            $builder->setBulletPoints($bulletPoints);
        }

        $builder->setBrand('VONJACOB');
        $builder->setPartNumber($model);
        $builder->setCountryOfOrigin((string) ($settings['amazon_country_of_origin'] ?? 'DE'));
        $builder->setSupplierDeclaredDgHzRegulation((string) ($settings['amazon_supplier_declared_dg_hz_regulation'] ?? 'not_applicable'));
        $builder->setJewelryMaterialCategorization((string) ($settings['amazon_jewelry_material_categorization'] ?? 'fashion'));
        $builder->setDepartment($this->normalizeDepartment((string) ($settings['amazon_department'] ?? 'Unisex')));
        $builder->setColor($ringColor);

        $material = $this->mapMaterial((string) ($productRow['customfield_asf_material'] ?? ''));
        if ($material !== '') {
            $builder->setMaterial($material);
        }

        $gemType = $this->mapGemType((string) ($productRow['customfield_asf_default_stone'] ?? ''));
        if ($gemType !== '') {
            $builder->setGemType($gemType);
        }

        $builder->setRawAttribute('condition_type', [[
            'value' => 'new_new',
            'marketplace_id' => $marketplaceId,
        ]]);

        $builder->setRawAttribute('manufacturer', [[
            'value' => 'VONJACOB',
            'marketplace_id' => $marketplaceId,
        ]]);

        $builder->setRawAttribute('model_number', [[
            'value' => $model,
            'marketplace_id' => $marketplaceId,
        ]]);

        $browseNode = trim((string) ($settings['amazon_recommended_browse_node'] ?? ''));
        if ($browseNode !== '' && ctype_digit($browseNode)) {
            $builder->setRecommendedBrowseNode((int) $browseNode);
        }

        $builder->setIsResizable(false);
        $builder->setBatteriesRequired(false);

        if ($this->isProductIdentifierExemptionEnabled($settings)) {
            $builder->setSupplierDeclaredHasProductIdentifierExemption(true);
        }

        $this->applyQualityAttributes(
            builder: $builder,
            productRow: $productRow,
            marketplaceId: $marketplaceId,
            languageTag: $languageTag
        );

        $this->applyGpsrAttributes(
            builder: $builder,
            settings: $settings,
            marketplaceId: $marketplaceId
        );

        if ($isParent) {
            return;
        }

        if ($damenSize !== null && $herrenSize !== null) {
            $pairSize = $damenSize . '/' . $herrenSize;

            $builder->setRingSize($pairSize);

            $builder->setRawAttribute('size', [[
                'value' => $pairSize,
                'marketplace_id' => $marketplaceId,
            ]]);

            $builder->setRawAttribute('target_gender', [[
                'value' => 'unisex',
                'marketplace_id' => $marketplaceId,
            ]]);
        }

        if ($quantity !== null) {
            $builder->setQuantity($quantity);
        }

        $pricing = $this->buildPricing($productRow);
        if ($pricing['price'] !== null) {
            $builder->setPrice($pricing['price']);
        }
        if ($pricing['list_price'] !== null) {
            $builder->setListPrice($pricing['list_price']);
        }

        $suggestedAsin = $this->normalizeAsin((string) ($productRow['suggested_asin'] ?? ''));
        if ($suggestedAsin !== '') {
            $builder->setMerchantSuggestedAsin($suggestedAsin);
        }

        if (!$this->isProductIdentifierExemptionEnabled($settings)) {
            $externalId = $this->resolveExternalProductId($productRow);
            if ($externalId !== null) {
                $builder->setExternalProductId($externalId['value'], $externalId['type']);
            }
        }

        if ($damenSize !== null) {
            $stones = AmazonStoneBuilder::buildFromProductRow($productRow, (float) $damenSize);
            if ($stones !== []) {
                $builder->setRawAttribute('stones', $stones);
            }
        }
    }

    private function applyQualityAttributes(
        AmazonListingPayloadBuilder $builder,
        array $productRow,
        string $marketplaceId,
        string $languageTag
    ): void {
        $metalTypes = $this->mapMetalTypes((string) ($productRow['customfield_asf_material'] ?? ''));
        if ($metalTypes !== []) {
            $entries = [];
            foreach ($metalTypes as $metalType) {
                $entries[] = [
                    'value' => $metalType,
                    'marketplace_id' => $marketplaceId,
                    'language_tag' => $languageTag,
                ];
            }
            $builder->setRawAttribute('metal_type', $entries);
        }

        $builder->setRawAttribute('occasion_type', [
            [
                'value' => 'Hochzeit',
                'marketplace_id' => $marketplaceId,
                'language_tag' => $languageTag,
            ],
            [
                'value' => 'Verlobung',
                'marketplace_id' => $marketplaceId,
                'language_tag' => $languageTag,
            ],
            [
                'value' => 'Jahrestag',
                'marketplace_id' => $marketplaceId,
                'language_tag' => $languageTag,
            ],
            [
                'value' => 'Valentinstag',
                'marketplace_id' => $marketplaceId,
                'language_tag' => $languageTag,
            ],
        ]);

        $builder->setRawAttribute('style', [[
            'value' => 'Modern',
            'marketplace_id' => $marketplaceId,
            'language_tag' => $languageTag,
        ]]);

        $builder->setRawAttribute('ring_form_type', [[
            'value' => 'band',
            'marketplace_id' => $marketplaceId,
            'language_tag' => $languageTag,
        ]]);
    }

    private function applyGpsrAttributes(
        AmazonListingPayloadBuilder $builder,
        array $settings,
        string $marketplaceId
    ): void {
        $manufacturerReference = trim((string) ($settings['amazon_gpsr_manufacturer_reference'] ?? ''));
        if ($manufacturerReference === '') {
            $manufacturerReference = trim((string) ($_ENV['AMAZON_GPSR_MANUFACTURER_REFERENCE'] ?? ''));
        }

        if ($manufacturerReference !== '') {
            $builder->setRawAttribute('gpsr_manufacturer_reference', [[
                'gpsr_manufacturer_email_address' => $manufacturerReference,
                'marketplace_id' => $marketplaceId,
            ]]);
        }

        $safetyAttestationRaw = trim((string) ($settings['amazon_gpsr_safety_attestation'] ?? ''));
        if ($safetyAttestationRaw === '') {
            $safetyAttestationRaw = trim((string) ($_ENV['AMAZON_GPSR_SAFETY_ATTESTATION'] ?? '0'));
        }

        $safetyAttestation = in_array(
            mb_strtolower($safetyAttestationRaw),
            ['1', 'true', 'yes', 'ja', 'on'],
            true
        );

        $builder->setRawAttribute('gpsr_safety_attestation', [[
            'value' => $safetyAttestation,
            'marketplace_id' => $marketplaceId,
        ]]);

        $complianceMediaUrl = trim((string) ($settings['amazon_compliance_media_url'] ?? ''));
        if ($complianceMediaUrl !== '') {
            $contentType = trim((string) ($settings['amazon_compliance_media_type'] ?? 'safety_information'));
            $builder->setRawAttribute('compliance_media', [[
                'marketplace_id' => $marketplaceId,
                'content_type' => $contentType,
                'content_language' => $this->getLanguageTag(),
                'source_location' => $complianceMediaUrl,
            ]]);
        }
    }

    private function createBaseBuilder(string $sellerSku): AmazonListingPayloadBuilder
    {
        return (new AmazonListingPayloadBuilder())
            ->setSellerSku($sellerSku)
            ->setProductType('RING')
            ->setRequirements('LISTING')
            ->setMarketplaceId($this->getMarketplaceId())
            ->setLanguageTag($this->getLanguageTag())
            ->setCurrency('EUR');
    }

    private function getAmazonSettings(): array
    {
        return $this->settingsService->getMany([
            'amazon_title_template',
            'amazon_description_template',
            'amazon_bullet_1',
            'amazon_bullet_2',
            'amazon_bullet_3',
            'amazon_bullet_4',
            'amazon_bullet_5',
            'amazon_bullet_6',
            'amazon_country_of_origin',
            'amazon_recommended_browse_node',
            'amazon_supplier_declared_dg_hz_regulation',
            'amazon_jewelry_material_categorization',
            'amazon_department',
            'amazon_supplier_declared_has_product_identifier_exemption',
            'amazon_gpsr_manufacturer_reference',
            'amazon_gpsr_safety_attestation',
            'amazon_compliance_media_url',
            'amazon_compliance_media_type',
        ]);
    }

    private function buildTitle(array $productRow, ?string $damenSize, ?string $herrenSize, array $settings): string
    {
        $template = trim((string) ($settings['amazon_title_template'] ?? ''));

        $vars = $this->buildTemplateVariables($productRow, $damenSize, $herrenSize);

        if ($template === '') {
            $base = trim((string) ($productRow['customfield_asf_model'] ?? ''));
            $material = trim((string) ($productRow['customfield_asf_material'] ?? ''));
            $type = trim((string) ($productRow['customfield_asf_type'] ?? 'Ring'));

            $title = trim($type . ' ' . $base . ' ' . $material);

            if ($damenSize !== null && $herrenSize !== null) {
                $title .= ' ' . $damenSize . '/' . $herrenSize;
            }

            return trim($title);
        }

        return $this->templateService->render($template, $vars);
    }

    private function buildDescription(array $productRow, ?string $damenSize, ?string $herrenSize, array $settings): string
    {
        $vars = $this->buildTemplateVariables($productRow, $damenSize, $herrenSize);
        $template = trim((string) ($settings['amazon_description_template'] ?? ''));

        if ($template !== '') {
            return $this->sanitizeDescription($this->templateService->render($template, $vars));
        }

        $model = trim((string) ($productRow['customfield_asf_model'] ?? ''));
        $material = trim((string) ($productRow['customfield_asf_material'] ?? ''));
        $width = $this->normalizeMeasure((string) ($productRow['customfield_asf_default_width'] ?? ''));
        $strength = $this->normalizeMeasure((string) ($productRow['customfield_asf_default_strength'] ?? ''));
        $stone = trim((string) ($productRow['customfield_asf_default_stone'] ?? ''));
        $stoneCount = trim((string) ($productRow['customfield_asf_stone_count'] ?? ''));

        $parts = [];

        $first = 'Partnerringe';
        if ($model !== '') {
            $first .= ' Modell ' . $model;
        }
        if ($material !== '') {
            $first .= ' aus ' . $material;
        }
        $parts[] = $first . '.';

        $specs = [];
        if ($width !== '') {
            $specs[] = 'Ringbreite: ' . $width . ' mm';
        }
        if ($strength !== '') {
            $specs[] = 'Stärke: ' . $strength . ' mm';
        }
        if ($specs !== []) {
            $parts[] = implode('. ', $specs) . '.';
        }

        if ($stone !== '') {
            $stoneSentence = 'Ein Ring ist';
            if ($stoneCount !== '' && ctype_digit($stoneCount) && (int) $stoneCount > 0) {
                $stoneSentence .= ' mit ' . $stoneCount . ' ' . $stone;
            } else {
                $stoneSentence .= ' mit ' . $stone;
            }
            $stoneSentence .= ' besetzt.';
            $parts[] = $stoneSentence;
        }

        $parts[] = 'Das Set wird mit Etui geliefert.';

        return $this->sanitizeDescription(implode(' ', $parts));
    }

    private function sanitizeDescription(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        $replacements = [
            '/\bgratis\b/iu' => '',
            '/\bkostenlos(?:e|er|es|en)?\b/iu' => '',
            '/\bversand\b/iu' => '',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        $value = trim($value, " \t\n\r\0\x0B.-,");

        return $value;
    }

    private function normalizeMeasure(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : '';
    }

    /**
     * @return array<int,string>
     */
    private function buildBulletPoints(array $productRow, ?string $damenSize, ?string $herrenSize, array $settings): array
    {
        $templates = [
            (string) ($settings['amazon_bullet_1'] ?? ''),
            (string) ($settings['amazon_bullet_2'] ?? ''),
            (string) ($settings['amazon_bullet_3'] ?? ''),
            (string) ($settings['amazon_bullet_4'] ?? ''),
            (string) ($settings['amazon_bullet_5'] ?? ''),
            (string) ($settings['amazon_bullet_6'] ?? ''),
        ];

        $vars = $this->buildTemplateVariables($productRow, $damenSize, $herrenSize);

        return $this->templateService->renderBulletPoints($templates, $vars);
    }

    private function buildTemplateVariables(array $productRow, ?string $damenSize, ?string $herrenSize): array
    {
        $vars = [];

        foreach ($productRow as $key => $value) {
            $vars[(string) $key] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        $vars['model'] = (string) ($productRow['customfield_asf_model'] ?? '');
        $vars['material'] = (string) ($productRow['customfield_asf_material'] ?? '');
        $vars['oo_id'] = (string) ($productRow['oo_id'] ?? '');
        $vars['damen_size'] = $damenSize ?? '';
        $vars['herren_size'] = $herrenSize ?? '';
        $vars['pair_size'] = ($damenSize !== null && $herrenSize !== null)
            ? ($damenSize . '/' . $herrenSize)
            : '';

        return $vars;
    }

    /**
     * @return array{price:?float,list_price:?float}
     */
    private function buildPricing(array $productRow): array
    {
        $priceGroup = trim((string) ($productRow['priceGroup'] ?? ''));
        if ($priceGroup === '') {
            return [
                'price' => null,
                'list_price' => null,
            ];
        }

        $base = $this->priceMarkupService->getZweipunktPricesForGroup($priceGroup);
        $markupMap = $this->priceMarkupService->getMarkupMap();

        $markup = $markupMap[$priceGroup] ?? [
                'markup_type' => 'absolute',
                'markup_value' => 0.0,
            ];

        $price = $this->priceMarkupService->applyMarkup(
            $base['discount_price'] ?? null,
            (string) ($markup['markup_type'] ?? 'absolute'),
            (float) ($markup['markup_value'] ?? 0.0)
        );

        $listPrice = $this->priceMarkupService->applyMarkup(
            $base['pseudo_price'] ?? null,
            (string) ($markup['markup_type'] ?? 'absolute'),
            (float) ($markup['markup_value'] ?? 0.0)
        );

        return [
            'price' => $price,
            'list_price' => $listPrice,
        ];
    }

    private function getMarketplaceId(): string
    {
        $value = trim((string) ($_ENV['AMAZON_MARKETPLACE_ID'] ?? ''));
        if ($value === '') {
            throw new RuntimeException('AMAZON_MARKETPLACE_ID fehlt in .env.');
        }

        return $value;
    }

    private function getLanguageTag(): string
    {
        $locale = trim((string) ($_ENV['AMAZON_LANGUAGE_TAG'] ?? 'de_DE'));
        return $locale !== '' ? $locale : 'de_DE';
    }

    private function normalizeDepartment(string $value): string
    {
        $value = trim($value);

        return match (mb_strtolower($value)) {
            'unisex-adult', 'unisex', 'unisex adult' => 'Unisex',
            'men', 'male', 'herren' => 'Men',
            'women', 'female', 'damen' => 'Women',
            default => $value !== '' ? $value : 'Unisex',
        };
    }

    private function mapGemType(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            'zirkonia', 'cubic zirconia' => 'Cubic_Zirconia',
            'diamant', 'diamond' => 'Diamond',
            default => 'Cubic_Zirconia',
        };
    }

    private function mapMaterial(string $value): string
    {
        $valueLower = mb_strtolower(trim($value));

        if ($valueLower === '') {
            return '';
        }

        if (str_contains($valueLower, 'keramik') && str_contains($valueLower, 'edelstahl')) {
            return 'Ceramic';
        }

        if (str_contains($valueLower, 'keramik')) {
            return 'Ceramic';
        }

        if (str_contains($valueLower, 'edelstahl')) {
            return 'Stainless_Steel';
        }

        if (str_contains($valueLower, 'carbon')) {
            return 'Carbon_Fiber';
        }

        if (str_contains($valueLower, 'titan')) {
            return 'Titanium';
        }

        if (str_contains($valueLower, 'silber')) {
            return 'Sterling_Silver';
        }

        return trim($value);
    }

    /**
     * @return array<int,string>
     */
    private function mapMetalTypes(string $value): array
    {
        $valueLower = mb_strtolower(trim($value));
        if ($valueLower === '') {
            return [];
        }

        $types = [];

        if (str_contains($valueLower, 'edelstahl')) {
            $types[] = 'Edelstahl';
        }

        if (str_contains($valueLower, 'titan')) {
            $types[] = 'Titan';
        }

        if (str_contains($valueLower, 'silber')) {
            $types[] = 'Silber';
        }

        if (str_contains($valueLower, 'gold')) {
            $types[] = 'Gold';
        }

        if (str_contains($valueLower, 'platin')) {
            $types[] = 'Platin';
        }

        if (str_contains($valueLower, 'palladium')) {
            $types[] = 'Palladium';
        }

        if (str_contains($valueLower, 'bronze')) {
            $types[] = 'Bronze';
        }

        if (str_contains($valueLower, 'messing')) {
            $types[] = 'Messing';
        }

        return array_values(array_slice(array_unique($types), 0, 3));
    }

    private function isProductIdentifierExemptionEnabled(array $settings): bool
    {
        $value = trim((string) ($settings['amazon_supplier_declared_has_product_identifier_exemption'] ?? '1'));
        return in_array(mb_strtolower($value), ['1', 'true', 'yes', 'ja', 'on'], true);
    }

    private function normalizeAsin(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z0-9]{10}$/', $value) === 1 ? $value : '';
    }

    /**
     * @return array{value:string,type:string}|null
     */
    private function resolveExternalProductId(array $productRow): ?array
    {
        $candidates = [
            'ean' => 'EAN',
            'customfield_ean' => 'EAN',
            'gtin' => 'GTIN',
            'upc' => 'UPC',
            'barcode' => 'EAN',
        ];

        foreach ($candidates as $column => $type) {
            $raw = trim((string) ($productRow[$column] ?? ''));
            if ($raw === '') {
                continue;
            }

            $value = preg_replace('/\D+/', '', $raw) ?? '';
            if ($value === '') {
                continue;
            }

            return [
                'value' => $value,
                'type' => $type,
            ];
        }

        return null;
    }

    private function upsertInventoryTrackingRow(
        string $model,
        string $parentSku,
        string $sku,
        string $damenSize,
        string $herrenSize,
        int $quantity,
        mixed $response
    ): void {
        $responseArr = $this->normalizeResponse($response);
        $status = strtoupper(trim((string) ($responseArr['status'] ?? '')));
        $message = trim((string) ($responseArr['message'] ?? 'Initialer Upload durchgeführt.'));

        if ($status !== '' && $status !== 'ACCEPTED') {
            return;
        }

        $stmt = $this->appPdo->prepare(
            'INSERT INTO amazon_listing_inventory
                (model, parent_sku, sku, damen_size, herren_size, asin, current_stock_quantity, last_pushed_quantity, last_feed_id, last_sync_status, last_sync_message, last_sync_at, is_active)
             VALUES
                (:model, :parent_sku, :sku, :damen_size, :herren_size, NULL, :current_stock_quantity, :last_pushed_quantity, NULL, :last_sync_status, :last_sync_message, CURRENT_TIMESTAMP, 1)
             ON DUPLICATE KEY UPDATE
                model = VALUES(model),
                parent_sku = VALUES(parent_sku),
                damen_size = VALUES(damen_size),
                herren_size = VALUES(herren_size),
                current_stock_quantity = VALUES(current_stock_quantity),
                last_pushed_quantity = VALUES(last_pushed_quantity),
                last_sync_status = VALUES(last_sync_status),
                last_sync_message = VALUES(last_sync_message),
                last_sync_at = VALUES(last_sync_at),
                is_active = 1,
                updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            'model' => $model,
            'parent_sku' => $parentSku,
            'sku' => $sku,
            'damen_size' => $damenSize,
            'herren_size' => $herrenSize,
            'current_stock_quantity' => max(0, $quantity),
            'last_pushed_quantity' => max(0, $quantity),
            'last_sync_status' => 'seeded',
            'last_sync_message' => $message !== '' ? $message : 'Initialer Upload durchgeführt.',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeResponse(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

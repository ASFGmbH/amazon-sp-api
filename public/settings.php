<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['username'])) {
    $url = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /../login.php?redirect=$url");
    exit();
}

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\Database;
use App\Services\SettingsService;
use App\Database\ProductPDO;
use App\Services\ProductPlaceholderService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$pdo = Database::get();
$settingsService = new SettingsService($pdo);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function settings_value(array $settings, string $key, string $default = ''): string
{
    $value = $settings[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function checked_if_truthy(array $settings, string $key, bool $default = false): string
{
    $value = $settings[$key] ?? null;
    if ($value === null || $value === '') {
        return $default ? 'checked' : '';
    }

    $normalized = mb_strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'ja', 'on'], true) ? 'checked' : '';
}

$successMessage = null;
$errorMessage = null;

$allSettingKeys = [
    'amazon_title_template',
    'amazon_description_template',
    'amazon_bullet_1',
    'amazon_bullet_2',
    'amazon_bullet_3',
    'amazon_bullet_4',
    'amazon_bullet_5',
    'amazon_bullet_6',
    'amazon_country_of_origin',
    'amazon_price.tax_rate',
    'amazon_price.round_to',
    'amazon_price.min_price',
    'amazon_price.default_price_group',
    'amazon_recommended_browse_node',
    'amazon_gem_type_default',
    'amazon_department',
    'amazon_color',
    'amazon_supplier_declared_dg_hz_regulation',
    'amazon_jewelry_material_categorization',
    'amazon_supplier_declared_has_product_identifier_exemption',
    'amazon_gpsr_manufacturer_reference',
    'amazon_gpsr_safety_attestation',
    'amazon_compliance_media_url',
    'amazon_compliance_media_type',
    'amazon_inventory.minstock',
    'amazon_inventory.finalize_report_enabled',
    'amazon_inventory.finalize_report_email',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'amazon_title_template' => trim((string) ($_POST['amazon_title_template'] ?? '')),
            'amazon_description_template' => trim((string) ($_POST['amazon_description_template'] ?? '')),
            'amazon_bullet_1' => trim((string) ($_POST['amazon_bullet_1'] ?? '')),
            'amazon_bullet_2' => trim((string) ($_POST['amazon_bullet_2'] ?? '')),
            'amazon_bullet_3' => trim((string) ($_POST['amazon_bullet_3'] ?? '')),
            'amazon_bullet_4' => trim((string) ($_POST['amazon_bullet_4'] ?? '')),
            'amazon_bullet_5' => trim((string) ($_POST['amazon_bullet_5'] ?? '')),
            'amazon_bullet_6' => trim((string) ($_POST['amazon_bullet_6'] ?? '')),
            'amazon_country_of_origin' => strtoupper(trim((string) ($_POST['amazon_country_of_origin'] ?? 'DE'))),
            'amazon_price.tax_rate' => str_replace(',', '.', trim((string) ($_POST['amazon_price_tax_rate'] ?? '1.19'))),
            'amazon_price.round_to' => str_replace(',', '.', trim((string) ($_POST['amazon_price_round_to'] ?? '5'))),
            'amazon_price.min_price' => str_replace(',', '.', trim((string) ($_POST['amazon_price_min_price'] ?? '0'))),
            'amazon_price.default_price_group' => trim((string) ($_POST['amazon_price_default_price_group'] ?? 'ring_default')),
            'amazon_recommended_browse_node' => trim((string) ($_POST['amazon_recommended_browse_node'] ?? '11961464031')),
            'amazon_gem_type_default' => trim((string) ($_POST['amazon_gem_type_default'] ?? 'Zirkonia')),
            'amazon_department' => trim((string) ($_POST['amazon_department'] ?? 'unisex-adult')),
            'amazon_color' => trim((string) ($_POST['amazon_color'] ?? 'silver')),
            'amazon_supplier_declared_dg_hz_regulation' => trim((string) ($_POST['amazon_supplier_declared_dg_hz_regulation'] ?? 'not_applicable')),
            'amazon_jewelry_material_categorization' => trim((string) ($_POST['amazon_jewelry_material_categorization'] ?? 'fashion')),
            'amazon_supplier_declared_has_product_identifier_exemption' => isset($_POST['amazon_supplier_declared_has_product_identifier_exemption']) ? '1' : '0',
            'amazon_gpsr_manufacturer_reference' => trim((string) ($_POST['amazon_gpsr_manufacturer_reference'] ?? 'https://vonjacob.de/')),
            'amazon_gpsr_safety_attestation' => trim((string) ($_POST['amazon_gpsr_safety_attestation'] ?? '0')),
            'amazon_compliance_media_url' => trim((string) ($_POST['amazon_compliance_media_url'] ?? '')),
            'amazon_compliance_media_type' => trim((string) ($_POST['amazon_compliance_media_type'] ?? 'safety_information')),
            'amazon_inventory.minstock' => trim((string) ($_POST['amazon_inventory_minstock'] ?? '0')),
            'amazon_inventory.finalize_report_enabled' => isset($_POST['amazon_inventory_finalize_report_enabled']) ? '1' : '0',
            'amazon_inventory.finalize_report_email' => trim((string) ($_POST['amazon_inventory_finalize_report_email'] ?? '')),
        ];

        if ($data['amazon_country_of_origin'] === '') {
            $data['amazon_country_of_origin'] = 'DE';
        }

        if (mb_strlen($data['amazon_country_of_origin']) > 2) {
            throw new RuntimeException('Herkunftsland bitte als 2-stelligen Code speichern, z. B. DE.');
        }

        foreach (['amazon_price.tax_rate', 'amazon_price.round_to', 'amazon_price.min_price'] as $numericKey) {
            $value = trim((string) $data[$numericKey]);
            if ($value === '' || !is_numeric($value)) {
                throw new RuntimeException("Ungültiger numerischer Wert für {$numericKey}.");
            }
        }

        if ($data['amazon_inventory.minstock'] === '' || !is_numeric($data['amazon_inventory.minstock'])) {
            throw new RuntimeException('Minstock muss numerisch sein.');
        }

        $data['amazon_inventory.minstock'] = (string) max(0, (int) floor((float) str_replace(',', '.', $data['amazon_inventory.minstock'])));

        if (
            $data['amazon_inventory.finalize_report_email'] !== ''
            && filter_var($data['amazon_inventory.finalize_report_email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException('Die Report-E-Mail-Adresse ist ungültig.');
        }

        if (
            $data['amazon_inventory.finalize_report_enabled'] === '1'
            && $data['amazon_inventory.finalize_report_email'] === ''
        ) {
            throw new RuntimeException('Bitte eine E-Mail-Adresse für den Finalize-Report hinterlegen.');
        }

        if ($data['amazon_price.default_price_group'] === '') {
            $data['amazon_price.default_price_group'] = 'ring_default';
        }

        if ($data['amazon_recommended_browse_node'] !== '' && !ctype_digit($data['amazon_recommended_browse_node'])) {
            throw new RuntimeException('Recommended Browse Node muss numerisch sein.');
        }

        if ($data['amazon_recommended_browse_node'] === '') {
            $data['amazon_recommended_browse_node'] = '11961464031';
        }

        if ($data['amazon_gem_type_default'] === '') {
            $data['amazon_gem_type_default'] = 'Zirkonia';
        }

        if ($data['amazon_department'] === '') {
            $data['amazon_department'] = 'unisex-adult';
        }

        if ($data['amazon_color'] === '') {
            $data['amazon_color'] = 'silver';
        }

        if ($data['amazon_supplier_declared_dg_hz_regulation'] === '') {
            $data['amazon_supplier_declared_dg_hz_regulation'] = 'not_applicable';
        }

        if ($data['amazon_jewelry_material_categorization'] === '') {
            $data['amazon_jewelry_material_categorization'] = 'fashion';
        }

        if ($data['amazon_gpsr_manufacturer_reference'] === '') {
            $data['amazon_gpsr_manufacturer_reference'] = 'https://vonjacob.de/';
        }

        if (
            $data['amazon_compliance_media_url'] !== ''
            && filter_var($data['amazon_compliance_media_url'], FILTER_VALIDATE_URL) === false
        ) {
            throw new RuntimeException('Compliance-Medien-URL ist ungültig.');
        }

        $allowedComplianceMediaTypes = [
            'certificate_of_analysis',
            'provider_fact_sheet',
            'application_guide',
            'user_manual',
            'user_guide',
            'emergency_use_authorization_amendment',
            'warranty',
            'instructions_for_use',
            'installation_manual',
            'compatibility_guide',
            'certificate_of_compliance',
            'product_certificate_of_conformity',
            'troubleshooting_guide',
            'emergency_use_authorization',
            'patient_fact_sheet',
            'safety_data_sheet',
            'safety_information',
            'specification_sheet',
            'data_transparency_declaration',
        ];

        if (!in_array($data['amazon_compliance_media_type'], $allowedComplianceMediaTypes, true)) {
            throw new RuntimeException('Ungültiger Inhaltstyp für konforme Medien.');
        }

        $settingsService->setMany($data);
        $successMessage = 'Einstellungen wurden gespeichert.';
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$settings = $settingsService->getMany($allSettingKeys);

$productPDO = ProductPDO::get();
$productPlaceholderService = new ProductPlaceholderService($productPDO);
$placeholders = $productPlaceholderService->getPlaceholders();
$sampleRow = $productPlaceholderService->getSampleRow();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Einstellungen - Amazon Ring Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme-dark.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="/../img/toolbox-favicon.png">
    <style>
        .card-body {
            color: white;
        }

        .placeholder-btn {
            font-family: monospace;
        }

        .placeholder-btn.copied {
            transform: scale(1.03);
            transition: transform 0.15s ease-in-out;
        }

        #preview-title,
        #preview-description,
        #preview-bullets-wrapper {
            min-height: 56px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 0.5rem;
            padding: 0.9rem;
        }

        #preview-title,
        #preview-description {
            white-space: pre-wrap;
            word-break: break-word;
        }

        #preview-bullets {
            margin-bottom: 0;
            padding-left: 1.2rem;
        }

        .form-text {
            color: rgba(255,255,255,0.65);
        }

        code {
            color: #9ec5fe;
        }

        .section-divider {
            border-top: 1px solid rgba(255,255,255,0.12);
            margin: 2rem 0 1.5rem;
            padding-top: 1.5rem;
        }

        .form-check-input {
            margin-top: .35rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">Amazon Ring Manager</a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Modelle</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="prices.php">Preis-Aufschläge</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="settings.php">Einstellungen</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/../index.php">Toolbox</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4">
    <h2 class="mb-4">Einstellungen</h2>

    <?php if ($successMessage !== null): ?>
        <div class="alert alert-success"><?= h($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
        <div class="card-body">

            <h4 class="mb-3">Amazon Vorlagen</h4>

            <div class="mb-3">
                <label for="amazon_title_template" class="form-label">Titel Vorlage</label>
                <textarea id="amazon_title_template" name="amazon_title_template" class="form-control template-input" rows="3"><?= h(settings_value($settings, 'amazon_title_template')) ?></textarea>
                <div class="form-text">Platzhalter können direkt angeklickt und kopiert werden.</div>
            </div>

            <div class="mb-3">
                <label for="amazon_description_template" class="form-label">Beschreibung Vorlage</label>
                <textarea id="amazon_description_template" name="amazon_description_template" class="form-control template-input" rows="4"><?= h(settings_value($settings, 'amazon_description_template')) ?></textarea>
                <div class="form-text">Leer lassen = neutrale Beschreibung automatisch aus Modell, Material, Breite, Stärke und Steinbesatz erzeugen.</div>
            </div>

            <div class="mb-3">
                <label for="amazon_bullet_1" class="form-label">Bulletpoint 1</label>
                <textarea id="amazon_bullet_1" name="amazon_bullet_1" class="form-control template-input" rows="2"><?= h(settings_value($settings, 'amazon_bullet_1')) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="amazon_bullet_2" class="form-label">Bulletpoint 2</label>
                <textarea id="amazon_bullet_2" name="amazon_bullet_2" class="form-control template-input" rows="2"><?= h(settings_value($settings, 'amazon_bullet_2')) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="amazon_bullet_3" class="form-label">Bulletpoint 3</label>
                <textarea id="amazon_bullet_3" name="amazon_bullet_3" class="form-control template-input" rows="2"><?= h(settings_value($settings, 'amazon_bullet_3')) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="amazon_bullet_4" class="form-label">Bulletpoint 4</label>
                <textarea id="amazon_bullet_4" name="amazon_bullet_4" class="form-control template-input" rows="2"><?= h(settings_value($settings, 'amazon_bullet_4')) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="amazon_bullet_5" class="form-label">Bulletpoint 5</label>
                <textarea id="amazon_bullet_5" name="amazon_bullet_5" class="form-control template-input" rows="2"><?= h(settings_value($settings, 'amazon_bullet_5')) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="amazon_bullet_6" class="form-label">Bulletpoint 6</label>
                <textarea id="amazon_bullet_6" name="amazon_bullet_6" class="form-control template-input" rows="2"><?= h(settings_value($settings, 'amazon_bullet_6')) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="amazon_country_of_origin" class="form-label">Herkunftsland</label>
                <input id="amazon_country_of_origin" name="amazon_country_of_origin" type="text" maxlength="2" class="form-control" value="<?= h(settings_value($settings, 'amazon_country_of_origin', 'DE')) ?>">
                <div class="form-text">ISO-Ländercode, z. B. DE</div>
            </div>

            <div class="section-divider">
                <h4 class="mb-3">Preislogik</h4>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <label for="amazon_price_tax_rate" class="form-label">Steuerfaktor</label>
                    <input id="amazon_price_tax_rate" name="amazon_price_tax_rate" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_price.tax_rate', '1.19')) ?>">
                    <div class="form-text">Beispiel: 1.19</div>
                </div>

                <div class="col-md-3">
                    <label for="amazon_price_round_to" class="form-label">Aufrunden auf</label>
                    <input id="amazon_price_round_to" name="amazon_price_round_to" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_price.round_to', '5')) ?>">
                    <div class="form-text">Beispiel: 5 für 5,00 €-Stufen</div>
                </div>

                <div class="col-md-3">
                    <label for="amazon_price_min_price" class="form-label">Mindestpreis</label>
                    <input id="amazon_price_min_price" name="amazon_price_min_price" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_price.min_price', '0')) ?>">
                    <div class="form-text">Untergrenze für Preislogik</div>
                </div>

                <div class="col-md-3">
                    <label for="amazon_price_default_price_group" class="form-label">Fallback Preisgruppe</label>
                    <input id="amazon_price_default_price_group" name="amazon_price_default_price_group" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_price.default_price_group', 'ring_default')) ?>">
                    <div class="form-text">Wird genutzt, wenn keine Preisgruppe vorhanden ist.</div>
                </div>
            </div>

            <div class="section-divider">
                <h4 class="mb-3">Amazon Standardwerte</h4>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label for="amazon_recommended_browse_node" class="form-label">Recommended Browse Node</label>
                    <input id="amazon_recommended_browse_node" name="amazon_recommended_browse_node" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_recommended_browse_node', '11961464031')) ?>">
                    <div class="form-text">Numerische Amazon Browse Node</div>
                </div>

                <div class="col-md-4">
                    <label for="amazon_gem_type_default" class="form-label">Standard Gem Type</label>
                    <input id="amazon_gem_type_default" name="amazon_gem_type_default" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_gem_type_default', 'Zirkonia')) ?>">
                </div>

                <div class="col-md-4">
                    <label for="amazon_department" class="form-label">Department</label>
                    <input id="amazon_department" name="amazon_department" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_department', 'unisex-adult')) ?>">
                </div>

                <div class="col-md-4">
                    <label for="amazon_color" class="form-label">Standardfarbe</label>
                    <input id="amazon_color" name="amazon_color" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_color', 'silver')) ?>">
                </div>

                <div class="col-md-4">
                    <label for="amazon_supplier_declared_dg_hz_regulation" class="form-label">DG-HZ Regulation</label>
                    <input id="amazon_supplier_declared_dg_hz_regulation" name="amazon_supplier_declared_dg_hz_regulation" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_supplier_declared_dg_hz_regulation', 'not_applicable')) ?>">
                </div>

                <div class="col-md-4">
                    <label for="amazon_jewelry_material_categorization" class="form-label">Jewelry Material Categorization</label>
                    <input id="amazon_jewelry_material_categorization" name="amazon_jewelry_material_categorization" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_jewelry_material_categorization', 'fashion')) ?>">
                </div>
            </div>

            <div class="section-divider">
                <h4 class="mb-3">Produktidentifikation & Compliance</h4>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="amazon_supplier_declared_has_product_identifier_exemption" name="amazon_supplier_declared_has_product_identifier_exemption" value="1" <?= checked_if_truthy($settings, 'amazon_supplier_declared_has_product_identifier_exemption', true) ?>>
                        <label class="form-check-label" for="amazon_supplier_declared_has_product_identifier_exemption">
                            Produktidentifier-Befreiung aktiv
                        </label>
                    </div>
                    <div class="form-text">Aktivieren, wenn das Listing ohne EAN/UPC/GTIN über GTIN-Exemption läuft.</div>
                </div>

                <div class="col-md-6">
                    <label for="amazon_gpsr_safety_attestation" class="form-label">GPSR-Sicherheitsbescheinigung</label>
                    <select id="amazon_gpsr_safety_attestation" name="amazon_gpsr_safety_attestation" class="form-select">
                        <option value="0" <?= settings_value($settings, 'amazon_gpsr_safety_attestation', '0') === '0' ? 'selected' : '' ?>>Nein</option>
                        <option value="1" <?= settings_value($settings, 'amazon_gpsr_safety_attestation', '0') === '1' ? 'selected' : '' ?>>Ja</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="amazon_gpsr_manufacturer_reference" class="form-label">GPSR Hersteller-Referenz</label>
                    <input id="amazon_gpsr_manufacturer_reference" name="amazon_gpsr_manufacturer_reference" type="text" class="form-control" value="<?= h(settings_value($settings, 'amazon_gpsr_manufacturer_reference', 'https://vonjacob.de/')) ?>">
                    <div class="form-text">E-Mail-Adresse oder URL des Herstellers. Für euch z. B. <code>https://vonjacob.de/</code>.</div>
                </div>

                <div class="col-md-6">
                    <label for="amazon_compliance_media_type" class="form-label">Compliance-Medien Inhaltstyp</label>
                    <select id="amazon_compliance_media_type" name="amazon_compliance_media_type" class="form-select">
                        <?php
                        $complianceType = settings_value($settings, 'amazon_compliance_media_type', 'safety_information');
                        $options = [
                            'certificate_of_analysis' => 'Analysezertifikat',
                            'provider_fact_sheet' => 'Anbieter-Informationsübersicht',
                            'application_guide' => 'Anwendungsleitfaden',
                            'user_manual' => 'Benutzerhandbuch',
                            'user_guide' => 'Benutzerleitfaden',
                            'warranty' => 'Garantie',
                            'instructions_for_use' => 'Gebrauchsanweisung',
                            'installation_manual' => 'Installationshandbuch',
                            'compatibility_guide' => 'Kompatibilitätsleitfaden',
                            'certificate_of_compliance' => 'Konformitätsbescheinigung',
                            'product_certificate_of_conformity' => 'Konformitätsbescheinigung für das Produkt',
                            'troubleshooting_guide' => 'Leitfaden zur Problembehebung',
                            'patient_fact_sheet' => 'Patienten-Informationsübersicht',
                            'safety_data_sheet' => 'Sicherheitsdatenblatt',
                            'safety_information' => 'Sicherheitsinformationen',
                            'specification_sheet' => 'Spezifikationsübersicht',
                            'data_transparency_declaration' => 'Transparenzerklärung gemäß Data Act',
                        ];
                        foreach ($options as $value => $label):
                            ?>
                            <option value="<?= h($value) ?>" <?= $complianceType === $value ? 'selected' : '' ?>><?= h($label . ' (' . $value . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label for="amazon_compliance_media_url" class="form-label">Compliance-Medien URL</label>
                    <input id="amazon_compliance_media_url" name="amazon_compliance_media_url" type="url" class="form-control" value="<?= h(settings_value($settings, 'amazon_compliance_media_url')) ?>">
                    <div class="form-text">Optionaler fixer Override. Leer lassen, wenn die PDF-URL pro Modell automatisch durch den Dokumentenservice gebaut wird.</div>
                </div>
            </div>

            <div class="section-divider">
                <h4 class="mb-3">Bestand & Cron-Report</h4>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label for="amazon_inventory_minstock" class="form-label">Minstock-Abzug</label>
                    <input id="amazon_inventory_minstock" name="amazon_inventory_minstock" type="number" min="0" step="1" class="form-control" value="<?= h(settings_value($settings, 'amazon_inventory.minstock', '0')) ?>">
                    <div class="form-text">Wird pro Child-SKU von der kleinsten Damen/Herren-Menge abgezogen. Beispiel: <code>2</code> hält zwei Sets als Reserve zurück.</div>
                </div>

                <div class="col-md-8">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="amazon_inventory_finalize_report_enabled" name="amazon_inventory_finalize_report_enabled" value="1" <?= checked_if_truthy($settings, 'amazon_inventory.finalize_report_enabled', false) ?>>
                        <label class="form-check-label" for="amazon_inventory_finalize_report_enabled">
                            Report an E-Mail senden
                        </label>
                    </div>
                    <div class="form-text">Gilt nur für <code>cron_inventory_finalize.php</code>. Wenn aktiv, wird nach dem Finalize-Lauf ein Report per E-Mail verschickt.</div>
                </div>

                <div class="col-md-6">
                    <label for="amazon_inventory_finalize_report_email" class="form-label">Report E-Mail-Adresse</label>
                    <input id="amazon_inventory_finalize_report_email" name="amazon_inventory_finalize_report_email" type="email" class="form-control" value="<?= h(settings_value($settings, 'amazon_inventory.finalize_report_email')) ?>">
                    <div class="form-text">Empfänger für den täglichen Finalize-Report.</div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>

        </div>
    </form>

    <div class="card shadow-sm mt-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Verfügbare Platzhalter aus PIMCORE/Spiegel-DB <code>object_query_1</code></h5>
                <small class="text-light-emphasis">Klick kopiert den Platzhalter</small>
            </div>

            <?php if ($placeholders === []): ?>
                <div class="text-muted">Keine Platzhalter gefunden.</div>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($placeholders as $ph): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary placeholder-btn" data-placeholder="<?= h($ph) ?>" title="In Zwischenablage kopieren"><?= h($ph) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-body">
            <h5 class="mb-3">Live-Vorschau</h5>

            <div class="mb-4">
                <label class="form-label fw-semibold">Titel Vorschau</label>
                <div id="preview-title">—</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Beschreibung Vorschau</label>
                <div id="preview-description">—</div>
            </div>

            <div>
                <label class="form-label fw-semibold">Bulletpoints Vorschau</label>
                <div id="preview-bullets-wrapper">
                    <ul id="preview-bullets"></ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const sampleData = <?= json_encode($sampleRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};

    function renderTemplate(template, data) {
        if (!template) {
            return '';
        }

        return template.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function(match, key) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                return String(data[key] ?? '');
            }
            return match;
        }).replace(/\s+/g, ' ').trim();
    }

    function updatePreview() {
        const titleInput = document.getElementById('amazon_title_template');
        const descriptionInput = document.getElementById('amazon_description_template');
        const titlePreview = document.getElementById('preview-title');
        const descriptionPreview = document.getElementById('preview-description');
        const bulletList = document.getElementById('preview-bullets');

        if (!titleInput || !descriptionInput || !titlePreview || !descriptionPreview || !bulletList) {
            return;
        }

        const titleRendered = renderTemplate(titleInput.value, sampleData);
        titlePreview.textContent = titleRendered !== '' ? titleRendered : '—';

        const descriptionRendered = renderTemplate(descriptionInput.value, sampleData);
        descriptionPreview.textContent = descriptionRendered !== '' ? descriptionRendered : '—';

        bulletList.innerHTML = '';

        for (let i = 1; i <= 6; i++) {
            const input = document.getElementById('amazon_bullet_' + i);
            if (!input) {
                continue;
            }

            const rendered = renderTemplate(input.value, sampleData);
            if (rendered === '') {
                continue;
            }

            const li = document.createElement('li');
            li.textContent = rendered;
            bulletList.appendChild(li);
        }

        if (bulletList.children.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'Keine Bulletpoints definiert.';
            bulletList.appendChild(li);
        }
    }

    async function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        try {
            const ok = document.execCommand('copy');
            document.body.removeChild(textarea);
            return ok;
        } catch (e) {
            document.body.removeChild(textarea);
            return false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.template-input').forEach(function (el) {
            el.addEventListener('input', updatePreview);
        });

        document.querySelectorAll('.placeholder-btn').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const placeholder = btn.getAttribute('data-placeholder') || '';
                const originalText = btn.textContent;
                const originalClass = btn.className;

                const ok = await copyToClipboard(placeholder);

                if (ok) {
                    btn.textContent = 'Kopiert';
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.add('btn-success', 'copied');
                } else {
                    btn.textContent = 'Fehler';
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.add('btn-danger');
                }

                window.setTimeout(function () {
                    btn.textContent = originalText;
                    btn.className = originalClass;
                }, 900);
            });
        });

        updatePreview();
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\Database;
use App\Database\ProductPDO;
use App\Database\ZweipunktPDO;
use App\Services\PriceMarkupService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function euro(?float $value): string
{
    return $value !== null
        ? number_format($value, 2, ',', '.') . ' €'
        : '—';
}

$appPdo = Database::get();
$productPdo = ProductPDO::get();
$zweipunktPdo = ZweipunktPDO::get();

$service = new PriceMarkupService($appPdo, $productPdo, $zweipunktPdo);

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $groups = $_POST['groups'] ?? [];
        $rows = [];

        foreach ($groups as $priceGroup => $row) {
            $markupValueRaw = trim((string) ($row['markup_value'] ?? '0'));
            $markupValueRaw = str_replace(',', '.', $markupValueRaw);

            $rows[] = [
                'price_group'  => (string) $priceGroup,
                'markup_type'  => (($row['markup_type'] ?? 'absolute') === 'percent') ? 'percent' : 'absolute',
                'markup_value' => is_numeric($markupValueRaw) ? (float) $markupValueRaw : 0.0,
            ];
        }

        $service->saveMany($rows);
        $successMessage = 'Preis-Aufschläge wurden gespeichert.';
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$priceGroups = $service->getDistinctPriceGroups();
$markupMap = $service->getMarkupMap();

$rows = [];

foreach ($priceGroups as $priceGroup) {
    $markup = $markupMap[$priceGroup] ?? [
            'price_group'  => $priceGroup,
            'markup_type'  => 'absolute',
            'markup_value' => 0.0,
        ];

    $basePrices = $service->getZweipunktPricesForGroup($priceGroup);

    $discountBase = $basePrices['discount_price'];
    $pseudoBase   = $basePrices['pseudo_price'];

    $discountFinal = $service->applyMarkup(
        $discountBase,
        $markup['markup_type'],
        $markup['markup_value']
    );

    $pseudoFinal = $service->applyMarkup(
        $pseudoBase,
        $markup['markup_type'],
        $markup['markup_value']
    );

    $rows[] = [
        'price_group'    => $priceGroup,
        'discount_base'  => $discountBase,
        'pseudo_base'    => $pseudoBase,
        'markup_type'    => $markup['markup_type'],
        'markup_value'   => $markup['markup_value'],
        'discount_final' => $discountFinal,
        'pseudo_final'   => $pseudoFinal,
    ];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Preis-Aufschläge - Amazon Ring Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme-dark.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="/../img/toolbox-favicon.png">

    <style>
        .preview-price {
            font-weight: 700;
            white-space: nowrap;
        }

        .base-price {
            opacity: 0.8;
            white-space: nowrap;
        }

        .markup-input {
            min-width: 110px;
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
                    <a class="nav-link active" href="prices.php">Preis-Aufschläge</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings.php">Einstellungen</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/../index.php">
                        Toolbox
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4">
    <h2 class="mb-4">Preis-Aufschläge</h2>

    <?php if ($successMessage !== null): ?>
        <div class="alert alert-success"><?= h($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
        <div class="card-body">
            <p class="text-muted mb-4">
                Grundlage: <code>object_query_1.priceGroup</code> → <code>zweipunkt_setting.priceGroup_X</code> und <code>priceGroup_X_pseudo</code>.
            </p>

            <div class="table-responsive">
                <table class="table table-dark table-striped align-middle">
                    <thead>
                    <tr>
                        <th>priceGroup</th>
                        <th>Aktueller Preis</th>
                        <th>Aktueller Streichpreis</th>
                        <th>Aufschlag-Typ</th>
                        <th>Aufschlag-Wert</th>
                        <th>Neuer Preis</th>
                        <th>Neuer Streichpreis</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr
                                class="price-row"
                                data-base-discount="<?= h($row['discount_base'] !== null ? number_format((float) $row['discount_base'], 2, '.', '') : '') ?>"
                                data-base-pseudo="<?= h($row['pseudo_base'] !== null ? number_format((float) $row['pseudo_base'], 2, '.', '') : '') ?>"
                        >
                            <td>
                                <strong><?= h($row['price_group']) ?></strong>
                            </td>

                            <td class="base-price">
                                <?= euro($row['discount_base']) ?>
                            </td>

                            <td class="base-price">
                                <?= euro($row['pseudo_base']) ?>
                            </td>

                            <td>
                                <select
                                        name="groups[<?= h($row['price_group']) ?>][markup_type]"
                                        class="form-select form-select-sm markup-type"
                                >
                                    <option value="absolute" <?= $row['markup_type'] === 'absolute' ? 'selected' : '' ?>>Absolut</option>
                                    <option value="percent" <?= $row['markup_type'] === 'percent' ? 'selected' : '' ?>>Prozentual</option>
                                </select>
                            </td>

                            <td>
                                <div class="input-group input-group-sm markup-input">
                                    <input
                                            type="number"
                                            step="0.01"
                                            name="groups[<?= h($row['price_group']) ?>][markup_value]"
                                            class="form-control markup-value"
                                            value="<?= h(number_format((float) $row['markup_value'], 2, '.', '')) ?>"
                                    >
                                    <span class="input-group-text markup-suffix">
                                        <?= $row['markup_type'] === 'percent' ? '%' : '€' ?>
                                    </span>
                                </div>
                            </td>

                            <td class="preview-price preview-discount">
                                <?= euro($row['discount_final']) ?>
                            </td>

                            <td class="preview-price preview-pseudo">
                                <?= euro($row['pseudo_final']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">Keine priceGroups gefunden.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </div>
    </form>
</div>

<script>
    function formatEuro(value) {
        if (value === null || value === '' || isNaN(value)) {
            return '—';
        }

        return Number(value).toLocaleString('de-DE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' €';
    }

    function applyMarkup(basePrice, type, markupValue) {
        if (basePrice === null || basePrice === '' || isNaN(basePrice)) {
            return null;
        }

        let result = Number(basePrice);
        const value = isNaN(markupValue) ? 0 : Number(markupValue);

        if (type === 'percent') {
            result += result * (value / 100);
        } else {
            result += value;
        }

        return Math.round(result * 100) / 100;
    }

    function updateRow(row) {
        const typeEl = row.querySelector('.markup-type');
        const valueEl = row.querySelector('.markup-value');
        const suffixEl = row.querySelector('.markup-suffix');
        const previewDiscountEl = row.querySelector('.preview-discount');
        const previewPseudoEl = row.querySelector('.preview-pseudo');

        const baseDiscount = parseFloat(row.dataset.baseDiscount);
        const basePseudo = parseFloat(row.dataset.basePseudo);

        const type = typeEl.value;
        const markupValue = parseFloat(String(valueEl.value).replace(',', '.'));

        suffixEl.textContent = type === 'percent' ? '%' : '€';

        const discountFinal = applyMarkup(baseDiscount, type, markupValue);
        const pseudoFinal = applyMarkup(basePseudo, type, markupValue);

        previewDiscountEl.textContent = formatEuro(discountFinal);
        previewPseudoEl.textContent = formatEuro(pseudoFinal);
    }

    function initPricePreview() {
        document.querySelectorAll('.price-row').forEach(function (row) {
            const typeEl = row.querySelector('.markup-type');
            const valueEl = row.querySelector('.markup-value');

            if (typeEl) {
                typeEl.addEventListener('change', function () {
                    updateRow(row);
                });
            }

            if (valueEl) {
                valueEl.addEventListener('input', function () {
                    updateRow(row);
                });
            }

            updateRow(row);
        });
    }

    document.addEventListener('DOMContentLoaded', initPricePreview);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
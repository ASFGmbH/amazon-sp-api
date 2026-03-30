<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\MediaScanner;
use App\Services\StockService;
use App\Database\Database;

session_start();

// Überprüfen, ob der Benutzer eingeloggt ist
if (!isset($_SESSION['username'])) {
    $url = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /../login.php?redirect=$url");
    exit();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$pdo = Database::get();
$stockService = new StockService();

$successMessage = null;
$errorMessage = null;

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Hilfsfunktion für sauberen Webpfad
 */
function mediaPath(string $absolutePath): string
{
    $projectRoot = realpath(__DIR__ . '/../');
    $realFile = realpath($absolutePath);

    if ($projectRoot === false || $realFile === false) {
        return '';
    }

    return str_replace('\\', '/', str_replace($projectRoot, '', $realFile));
}

/**
 * @return array<string,string>
 */
function loadRingColors(\PDO $pdo): array
{
    $stmt = $pdo->query("SELECT model, amazon_color FROM ring_color");

    $out = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $model = strtoupper(trim((string) ($row['model'] ?? '')));
        $color = trim((string) ($row['amazon_color'] ?? ''));

        if ($model !== '' && $color !== '') {
            $out[$model] = $color;
        }
    }

    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_ring_color') {
    try {
        $model = strtoupper(trim((string) ($_POST['model'] ?? '')));
        $amazonColor = trim((string) ($_POST['amazon_color'] ?? ''));

        $allowedColors = ['silver', 'black', 'brown', 'rose', 'gold', 'white'];

        if ($model === '') {
            throw new \RuntimeException('Modell fehlt.');
        }

        if (!in_array($amazonColor, $allowedColors, true)) {
            throw new \RuntimeException('Ungültige Hauptfarbe.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO ring_color (model, amazon_color)
             VALUES (:model, :amazon_color)
             ON DUPLICATE KEY UPDATE
                amazon_color = VALUES(amazon_color),
                updated_at = CURRENT_TIMESTAMP"
        );

        $stmt->execute([
            'model' => $model,
            'amazon_color' => $amazonColor,
        ]);

        $successMessage = "Hauptfarbe für Modell {$model} wurde gespeichert.";
    } catch (\Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$scanner = new MediaScanner();
$models = $scanner->scan(__DIR__ . '/../media');

$ringColors = loadRingColors($pdo);

$colorOptions = [
    'silver' => 'Silber',
    'black'  => 'Schwarz',
    'brown'  => 'Braun',
    'rose'   => 'Rosé',
    'gold'   => 'Gelb',
    'white'  => 'Weiss',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Amazon Ring Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme-dark.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="/../img/toolbox-favicon.png">
    <style>
        .card-img-top {
            width: 250px;
            height: 250px;
            margin: auto;
            object-fit: cover;
        }
        .thumb {
            height: 50px;
            margin-right: 5px;
            object-fit: cover;
            border-radius: 4px;
        }
        .stock-info span {
            margin-right: 6px;
        }
        .badge {
            font-size: 0.75rem;
            cursor: pointer;
        }
        .ring-color-form {
            max-width: 260px;
            margin: 1rem auto 0;
            text-align: left;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom">
    <div class="container">

        <a class="navbar-brand fw-bold" href="index.php">
            Amazon Ring Manager
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">

            <ul class="navbar-nav me-auto">

                <li class="nav-item">
                    <a class="nav-link active" href="index.php">Modelle</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="prices.php">Preis-Aufschläge</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings.php">Einstellungen</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/../index.php">Toolbox</a>
                </li>
            </ul>

        </div>

    </div>
</nav>

<div class="container py-5">
    <h2 class="mb-4">Amazon Ring Modelle</h2>

    <?php if ($successMessage !== null): ?>
        <div class="alert alert-success">
            <?= h($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger">
            <?= h($errorMessage) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <?php foreach ($models as $model): ?>

            <?php
            $modelCode = strtoupper((string) ($model['model'] ?? ''));
            $stock = $stockService->getStructuredStock($modelCode);
            $isOutOfStock = ((int) ($stock['total'] ?? 0)) <= 0;
            $variantBadges = $stockService->getVariantBadges($modelCode);
            $savedColor = $ringColors[$modelCode] ?? '';
            $hasMainImage = !empty($model['main']);
            $canPush = !$isOutOfStock && $savedColor !== '' && $hasMainImage;
            $mainImagePath = $hasMainImage ? mediaPath((string) $model['main']) : '';
            $galleryImages = is_array($model['gallery'] ?? null) ? $model['gallery'] : [];
            ?>

            <div class="col-md-4">
                <div class="card shadow-sm h-100">

                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold">
                            SKU: <?= h($modelCode) ?>
                        </span>

                        <?php if ($isOutOfStock): ?>
                            <span class="badge bg-danger">Nicht lagernd</span>
                        <?php else: ?>
                            <span class="badge bg-success">
                                <?= (int) $stock['total'] ?> Stück
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasMainImage && $mainImagePath !== ''): ?>
                        <img
                                data-src="./..<?= h($mainImagePath) ?>"
                                class="card-img-top lazy"
                                alt="Hauptbild <?= h($modelCode) ?>">
                    <?php endif; ?>

                    <?php if (!empty($variantBadges)): ?>
                        <div class="px-2 pt-2 text-center">
                            <?php foreach ($variantBadges as $variant): ?>
                                <?php
                                $tooltipText = ((int) ($variant['qty'] ?? 0)) > 0
                                    ? 'Größe ' . (string) ($variant['label'] ?? '') . ' – ' . (int) ($variant['qty'] ?? 0) . ' Stück lagernd'
                                    : 'Größe ' . (string) ($variant['label'] ?? '') . ' – Nicht lagernd';
                                ?>

                                <span
                                        class="badge <?= ((int) ($variant['qty'] ?? 0)) > 0 ? 'bg-success' : 'bg-danger' ?> me-1 mb-1"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        data-bs-title="<?= h($tooltipText) ?>">
                                    <?= h((string) ($variant['label'] ?? '')) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="card-body text-center">

                        <button
                                class="btn <?= $canPush ? 'btn-primary' : 'btn-secondary' ?> push-btn"
                                data-model="<?= h($modelCode) ?>"
                                data-dry-run="1"
                            <?= $canPush ? '' : 'disabled' ?>>
                            <?php if ($isOutOfStock): ?>
                                Nicht verfügbar
                            <?php elseif ($savedColor === ''): ?>
                                Hauptfarbe fehlt
                            <?php elseif (!$hasMainImage): ?>
                                Hauptbild fehlt
                            <?php else: ?>
                                In Amazon prüfen
                            <?php endif; ?>
                        </button>

                        <form method="post" class="ring-color-form">
                            <input type="hidden" name="action" value="save_ring_color">
                            <input type="hidden" name="model" value="<?= h($modelCode) ?>">

                            <label for="amazon_color_<?= h($modelCode) ?>" class="form-label mt-3" style="color:white">
                                Hauptfarbe
                            </label>

                            <select
                                    id="amazon_color_<?= h($modelCode) ?>"
                                    name="amazon_color"
                                    class="form-select"
                                    onchange="this.form.submit()"
                            >
                                <option value="" <?= $savedColor === '' ? 'selected' : '' ?> disabled>
                                    Bitte auswählen
                                </option>

                                <?php foreach ($colorOptions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $savedColor === $value ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                    </div>

                    <div class="card-footer">
                        <?php foreach ($galleryImages as $img): ?>
                            <?php $galleryPath = mediaPath((string) $img); ?>
                            <?php if ($galleryPath !== ''): ?>
                                <img
                                        data-src="./..<?= h($galleryPath) ?>"
                                        class="thumb lazy"
                                        alt="Nebenbild <?= h($modelCode) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>

        <?php endforeach; ?>

    </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
    <div id="liveToast" class="toast align-items-center text-bg-dark border-0">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
</script>

<script src="assets/js/app.js"></script>

</body>
</html>
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\AmazonInventorySyncService;
use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

try {
    $isCli = PHP_SAPI === 'cli';
    $configuredToken = trim((string) ($_ENV['CRON_TOKEN'] ?? ''));
    $providedToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

    if (!$isCli) {
        if ($configuredToken === '' || !hash_equals($configuredToken, $providedToken)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Cron-Token ungültig oder fehlt.',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }

    $model = trim((string) ($_GET['model'] ?? $_POST['model'] ?? ''));
    $dryRunRaw = (string) ($_GET['dry_run'] ?? $_POST['dry_run'] ?? '0');
    $dryRun = in_array(strtolower($dryRunRaw), ['1', 'true', 'yes', 'ja', 'on'], true);

    $service = new AmazonInventorySyncService();
    $result = $service->syncTrackedInventory($model !== '' ? $model : null, $dryRun);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

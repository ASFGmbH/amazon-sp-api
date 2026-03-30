<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\AmazonPushService;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Nur POST erlaubt.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $model = trim((string) ($_POST['model'] ?? ''));
    $dryRunRaw = (string) ($_POST['dry_run'] ?? '1');
    $dryRun = in_array($dryRunRaw, ['1', 'true', 'yes'], true);

    if ($model === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Parameter "model" fehlt.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $service = new AmazonPushService();
    $result = $service->pushModel($model, $dryRun);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
} catch (\Throwable $e) {
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

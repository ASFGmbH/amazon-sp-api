<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use App\Services\AmazonInventorySyncService;
use App\Services\SettingsService;
use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

/**
 * @return array{enabled:bool,email:?string}
 */
function loadReportMailSettings(): array
{
    $settingsService = new SettingsService(Database::get());
    return $settingsService->getAmazonInventoryFinalizeReportSettings();
}

function buildReportSubject(array $result, ?string $model = null, ?string $feedId = null): string
{
    $parts = ['Amazon Finalize Report'];

    if ($model !== null && $model !== '') {
        $parts[] = 'Modell ' . strtoupper($model);
    }

    if ($feedId !== null && $feedId !== '') {
        $parts[] = 'Feed ' . $feedId;
    }

    $parts[] = 'Feeds: ' . (int) ($result['feeds_checked'] ?? 0);
    $parts[] = 'OK: ' . (int) ($result['success_count'] ?? 0);
    $parts[] = 'Fehler: ' . (int) ($result['error_count'] ?? 0);

    return implode(' | ', $parts);
}

function buildReportBody(array $result, ?string $model = null, ?string $feedId = null): string
{
    $lines = [];
    $lines[] = 'Amazon Inventory Finalize Report';
    $lines[] = str_repeat('=', 32);
    $lines[] = 'Zeitpunkt: ' . date('Y-m-d H:i:s');
    $lines[] = 'Erfolg: ' . (!empty($result['success']) ? 'ja' : 'nein');
    $lines[] = 'Nachricht: ' . (string) ($result['message'] ?? '');
    $lines[] = 'Pending Count: ' . (int) ($result['pending_count'] ?? 0);
    $lines[] = 'Feeds geprüft: ' . (int) ($result['feeds_checked'] ?? 0);
    $lines[] = 'Erfolgreich bestätigt: ' . (int) ($result['success_count'] ?? 0);
    $lines[] = 'Fehlerhaft: ' . (int) ($result['error_count'] ?? 0);

    if ($model !== null && $model !== '') {
        $lines[] = 'Modell-Filter: ' . strtoupper($model);
    }

    if ($feedId !== null && $feedId !== '') {
        $lines[] = 'Feed-Filter: ' . $feedId;
    }

    $lines[] = '';
    $lines[] = 'Feeds';
    $lines[] = str_repeat('-', 32);

    $feeds = is_array($result['feeds'] ?? null) ? $result['feeds'] : [];
    if ($feeds === []) {
        $lines[] = 'Keine Feed-Daten vorhanden.';
    } else {
        foreach ($feeds as $feed) {
            if (!is_array($feed)) {
                continue;
            }

            $lines[] = sprintf(
                'Feed %s | Status: %s | Rows: %d | ResultDoc: %s',
                (string) ($feed['feed_id'] ?? '—'),
                (string) ($feed['processing_status'] ?? 'UNKNOWN'),
                (int) ($feed['row_count'] ?? 0),
                (string) ($feed['result_document_id'] ?? '—')
            );

            $downloadError = trim((string) ($feed['download_error'] ?? ''));
            if ($downloadError !== '') {
                $lines[] = '  Download-Fehler: ' . $downloadError;
            }
        }
    }

    $lines[] = '';
    $lines[] = 'Zeilen';
    $lines[] = str_repeat('-', 32);

    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    if ($rows === []) {
        $lines[] = 'Keine Zeilen im Finalize-Lauf.';
    } else {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '[%s] %s | Feed: %s | %s',
                strtoupper((string) ($row['status'] ?? 'INFO')),
                (string) ($row['sku'] ?? '—'),
                (string) ($row['feed_id'] ?? '—'),
                (string) ($row['message'] ?? '')
            );
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @return array{attempted:bool,sent:bool,message:string,to:?string,subject:?string}
 */
function maybeSendReportEmail(array $result, ?string $model = null, ?string $feedId = null): array
{
    $mailSettings = loadReportMailSettings();
    $enabled = (bool) ($mailSettings['enabled'] ?? false);
    $to = $mailSettings['email'] ?? null;
    $to = is_string($to) ? trim($to) : null;

    if (!$enabled) {
        return [
            'attempted' => false,
            'sent' => false,
            'message' => 'E-Mail-Report ist deaktiviert.',
            'to' => $to !== '' ? $to : null,
            'subject' => null,
        ];
    }

    if ($to === null || $to === '') {
        return [
            'attempted' => false,
            'sent' => false,
            'message' => 'E-Mail-Report ist aktiv, aber keine Empfängeradresse ist hinterlegt.',
            'to' => null,
            'subject' => null,
        ];
    }

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return [
            'attempted' => false,
            'sent' => false,
            'message' => 'Die hinterlegte Empfängeradresse ist ungültig.',
            'to' => $to,
            'subject' => null,
        ];
    }

    $subject = buildReportSubject($result, $model, $feedId);
    $body = buildReportBody($result, $model, $feedId);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $mailFrom = trim((string) ($_ENV['MAIL_FROM'] ?? ''));
    if ($mailFrom !== '' && filter_var($mailFrom, FILTER_VALIDATE_EMAIL) !== false) {
        $headers[] = 'From: ' . $mailFrom;
        $headers[] = 'Reply-To: ' . $mailFrom;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent = @mail($to, $encodedSubject, $body, implode("\r\n", $headers));

    return [
        'attempted' => true,
        'sent' => $sent,
        'message' => $sent ? 'E-Mail-Report wurde versendet.' : 'E-Mail-Report konnte nicht versendet werden.',
        'to' => $to,
        'subject' => $subject,
    ];
}

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
    $feedId = trim((string) ($_GET['feed_id'] ?? $_POST['feed_id'] ?? ''));

    $service = new AmazonInventorySyncService();
    $result = $service->finalizeSubmittedFeeds(
        $model !== '' ? $model : null,
        $feedId !== '' ? $feedId : null
    );

    $result['email_report'] = maybeSendReportEmail(
        $result,
        $model !== '' ? $model : null,
        $feedId !== '' ? $feedId : null
    );

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

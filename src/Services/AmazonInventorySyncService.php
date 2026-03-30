<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use PDO;
use RuntimeException;

final class AmazonInventorySyncService
{
    private PDO $appPdo;
    private StockService $stockService;
    private AmazonService $amazonService;
    private SettingsService $settingsService;

    public function __construct(
        ?PDO $appPdo = null,
        ?StockService $stockService = null,
        ?AmazonService $amazonService = null,
        ?SettingsService $settingsService = null
    ) {
        $this->appPdo = $appPdo ?? Database::get();
        $this->stockService = $stockService ?? new StockService();
        $this->amazonService = $amazonService ?? new AmazonService();
        $this->settingsService = $settingsService ?? new SettingsService($this->appPdo);
    }

    public function syncTrackedInventory(?string $model = null, bool $dryRun = false): array
    {
        $rows = $this->loadTrackedRows($model);
        if ($rows === []) {
            return [
                'success' => true,
                'dry_run' => $dryRun,
                'message' => 'Keine getrackten Amazon-SKUs gefunden.',
                'changed_count' => 0,
                'submitted_count' => 0,
                'messages' => [],
            ];
        }

        $stockByModel = [];
        $messages = [];
        $changedRows = [];
        $messageId = 1;
        $minstock = $this->settingsService->getAmazonInventoryMinstock();

        foreach ($rows as $row) {
            $rowModel = strtoupper(trim((string) ($row['model'] ?? '')));
            if ($rowModel === '') {
                continue;
            }

            if (!isset($stockByModel[$rowModel])) {
                $stockByModel[$rowModel] = $this->stockService->getStructuredStock($rowModel);
            }

            $expectedQuantity = $this->calculatePairQuantity(
                $stockByModel[$rowModel],
                (string) $row['damen_size'],
                (string) $row['herren_size'],
                $minstock
            );
            $lastPushedQuantity = (int) ($row['last_pushed_quantity'] ?? 0);

            if ($expectedQuantity === $lastPushedQuantity) {
                $this->touchUnchangedRow((int) $row['id'], $expectedQuantity);
                continue;
            }

            $messages[] = $this->buildInventoryPatchMessage(
                messageId: $messageId,
                sku: (string) $row['sku'],
                quantity: $expectedQuantity
            );

            $changedRows[] = [
                'id' => (int) $row['id'],
                'sku' => (string) $row['sku'],
                'model' => $rowModel,
                'quantity' => $expectedQuantity,
                'message_id' => $messageId,
                'minstock' => $minstock,
            ];

            $messageId++;
        }

        if ($messages === []) {
            return [
                'success' => true,
                'dry_run' => $dryRun,
                'message' => 'Keine Bestandsänderungen vorhanden.',
                'changed_count' => 0,
                'submitted_count' => 0,
                'messages' => [],
            ];
        }

        $feedPayload = $this->buildJsonListingsFeedPayload($messages);

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'message' => 'Bestands-Feed-Vorschau erzeugt.',
                'changed_count' => count($changedRows),
                'submitted_count' => 0,
                'messages' => $messages,
                'feed_payload' => $feedPayload,
                'changed_rows' => $changedRows,
            ];
        }

        $feedResult = $this->amazonService->submitJsonListingsFeed($feedPayload);
        $feedId = trim((string) ($feedResult['feedId'] ?? ''));

        foreach ($changedRows as $row) {
            $this->markRowAsSubmitted(
                id: (int) $row['id'],
                quantity: (int) $row['quantity'],
                feedId: $feedId,
                message: 'JSON_LISTINGS_FEED eingereicht.'
            );
        }

        return [
            'success' => true,
            'dry_run' => false,
            'message' => 'Bestands-Feed eingereicht.',
            'changed_count' => count($changedRows),
            'submitted_count' => count($changedRows),
            'feed' => $feedResult,
            'changed_rows' => $changedRows,
        ];
    }

    public function finalizeSubmittedFeeds(?string $model = null, ?string $feedId = null): array
    {
        $rows = $this->loadPendingRows($model, $feedId);
        if ($rows === []) {
            return [
                'success' => true,
                'message' => 'Keine eingereichten Bestands-Feeds zur Finalisierung gefunden.',
                'pending_count' => 0,
                'feeds_checked' => 0,
                'rows' => [],
            ];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $rowFeedId = trim((string) ($row['last_feed_id'] ?? ''));
            if ($rowFeedId === '') {
                continue;
            }
            $grouped[$rowFeedId][] = $row;
        }

        if ($grouped === []) {
            return [
                'success' => true,
                'message' => 'Es gibt zwar offene Datensätze, aber keine last_feed_id.',
                'pending_count' => count($rows),
                'feeds_checked' => 0,
                'rows' => [],
            ];
        }

        $summaryRows = [];
        $feeds = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($grouped as $currentFeedId => $feedRows) {
            $feedStatus = $this->amazonService->getFeedStatusWithResultDocument($currentFeedId);
            $feed = (array) ($feedStatus['feed'] ?? []);
            $processingStatus = strtoupper(trim((string) ($feed['processingStatus'] ?? 'UNKNOWN')));
            $report = $feedStatus['result_document'] ?? null;
            $rawReport = (string) ($feedStatus['result_document_raw'] ?? '');
            $downloadError = trim((string) ($feedStatus['download_error'] ?? ''));

            $issuesBySku = $this->extractIssuesBySku($report, $rawReport);
            $generalReportMessage = $this->buildGeneralReportMessage($report, $rawReport, $downloadError);

            $feeds[] = [
                'feed_id' => $currentFeedId,
                'processing_status' => $processingStatus,
                'result_document_id' => $feedStatus['result_document_id'] ?? null,
                'download_error' => $downloadError !== '' ? $downloadError : null,
                'row_count' => count($feedRows),
            ];

            foreach ($feedRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $sku = (string) ($row['sku'] ?? '');
                $quantity = (int) ($row['current_stock_quantity'] ?? 0);

                if ($processingStatus === 'IN_QUEUE' || $processingStatus === 'IN_PROGRESS') {
                    $message = $processingStatus === 'IN_QUEUE'
                        ? 'Feed ist noch in der Amazon-Warteschlange.'
                        : 'Feed wird von Amazon noch verarbeitet.';

                    $this->updateRowProgress($id, $processingStatus, $message);
                    $summaryRows[] = [
                        'id' => $id,
                        'sku' => $sku,
                        'feed_id' => $currentFeedId,
                        'status' => strtolower($processingStatus),
                        'message' => $message,
                    ];
                    continue;
                }

                if ($processingStatus === 'DONE') {
                    $skuIssues = $issuesBySku[$sku] ?? [];
                    $hasHardError = $this->hasHardError($skuIssues);

                    if ($hasHardError) {
                        $message = $this->buildIssueMessage($skuIssues);
                        $this->markRowAsError($id, 'error', $message !== '' ? $message : 'Amazon meldet Fehler im Processing Report.');
                        $summaryRows[] = [
                            'id' => $id,
                            'sku' => $sku,
                            'feed_id' => $currentFeedId,
                            'status' => 'error',
                            'message' => $message,
                        ];
                        $errorCount++;
                        continue;
                    }

                    $message = $generalReportMessage !== ''
                        ? 'Amazon-Feed verarbeitet. ' . $generalReportMessage
                        : 'Amazon-Feed erfolgreich verarbeitet.';

                    $this->markRowAsConfirmed($id, $quantity, $currentFeedId, $message);
                    $summaryRows[] = [
                        'id' => $id,
                        'sku' => $sku,
                        'feed_id' => $currentFeedId,
                        'status' => 'success',
                        'message' => $message,
                    ];
                    $successCount++;
                    continue;
                }

                $terminalStatus = in_array($processingStatus, ['CANCELLED', 'FATAL'], true)
                    ? strtolower($processingStatus)
                    : 'error';

                $message = $generalReportMessage !== ''
                    ? $generalReportMessage
                    : ('Amazon meldet Feed-Status ' . $processingStatus . '.');

                $this->markRowAsError($id, $terminalStatus, $message);
                $summaryRows[] = [
                    'id' => $id,
                    'sku' => $sku,
                    'feed_id' => $currentFeedId,
                    'status' => $terminalStatus,
                    'message' => $message,
                ];
                $errorCount++;
            }
        }

        return [
            'success' => true,
            'message' => 'Feed-Status geprüft.',
            'pending_count' => count($rows),
            'feeds_checked' => count($feeds),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'feeds' => $feeds,
            'rows' => $summaryRows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTrackedRows(?string $model = null): array
    {
        $sql = 'SELECT * FROM amazon_listing_inventory WHERE is_active = 1';
        $params = [];

        if ($model !== null && trim($model) !== '') {
            $sql .= ' AND model = :model';
            $params['model'] = strtoupper(trim($model));
        }

        $sql .= ' ORDER BY model, CAST(damen_size AS UNSIGNED), CAST(herren_size AS UNSIGNED), sku';

        $stmt = $this->appPdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPendingRows(?string $model = null, ?string $feedId = null): array
    {
        $sql = "SELECT *
                FROM amazon_listing_inventory
                WHERE is_active = 1
                  AND last_feed_id IS NOT NULL
                  AND last_feed_id <> ''
                  AND last_sync_status IN ('submitted', 'in_queue', 'in_progress', 'IN_QUEUE', 'IN_PROGRESS')";
        $params = [];

        if ($model !== null && trim($model) !== '') {
            $sql .= ' AND model = :model';
            $params['model'] = strtoupper(trim($model));
        }

        if ($feedId !== null && trim($feedId) !== '') {
            $sql .= ' AND last_feed_id = :feed_id';
            $params['feed_id'] = trim($feedId);
        }

        $sql .= ' ORDER BY last_feed_id, model, CAST(damen_size AS UNSIGNED), CAST(herren_size AS UNSIGNED), sku';

        $stmt = $this->appPdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private function calculatePairQuantity(array $stock, string $damenSize, string $herrenSize, int $minstock = 0): int
    {
        return $this->stockService->calculateAmazonChildQuantity($stock, $damenSize, $herrenSize, $minstock);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInventoryPatchMessage(int $messageId, string $sku, int $quantity): array
    {
        return [
            'messageId' => $messageId,
            'sku' => $sku,
            'operationType' => 'PATCH',
            'productType' => 'PRODUCT',
            'patches' => [[
                'op' => 'replace',
                'path' => '/attributes/fulfillment_availability',
                'value' => [[
                    'fulfillment_channel_code' => 'DEFAULT',
                    'quantity' => max(0, $quantity),
                ]],
            ]],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function buildJsonListingsFeedPayload(array $messages): array
    {
        $sellerId = trim((string) ($_ENV['AMAZON_SELLER_ID'] ?? ''));
        if ($sellerId === '') {
            throw new RuntimeException('AMAZON_SELLER_ID fehlt in .env.');
        }

        return [
            'header' => [
                'sellerId' => $sellerId,
                'version' => '2.0',
                'issueLocale' => 'de_DE',
                'report' => [
                    'includedData' => ['issues'],
                    'apiVersion' => '2021-08-01',
                ],
            ],
            'messages' => $messages,
        ];
    }

    private function touchUnchangedRow(int $id, int $quantity): void
    {
        $stmt = $this->appPdo->prepare(
            'UPDATE amazon_listing_inventory
             SET current_stock_quantity = :quantity,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'quantity' => $quantity,
        ]);
    }

    private function markRowAsSubmitted(int $id, int $quantity, string $feedId, string $message): void
    {
        $stmt = $this->appPdo->prepare(
            'UPDATE amazon_listing_inventory
             SET current_stock_quantity = :quantity,
                 last_feed_id = :feed_id,
                 last_sync_status = :status,
                 last_sync_message = :message,
                 last_sync_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'quantity' => $quantity,
            'feed_id' => $feedId !== '' ? $feedId : null,
            'status' => 'submitted',
            'message' => $message,
        ]);
    }

    private function updateRowProgress(int $id, string $status, string $message): void
    {
        $stmt = $this->appPdo->prepare(
            'UPDATE amazon_listing_inventory
             SET last_sync_status = :status,
                 last_sync_message = :message,
                 last_sync_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'status' => strtolower($status),
            'message' => $message,
        ]);
    }

    private function markRowAsConfirmed(int $id, int $quantity, string $feedId, string $message): void
    {
        $stmt = $this->appPdo->prepare(
            'UPDATE amazon_listing_inventory
             SET current_stock_quantity = :quantity,
                 last_pushed_quantity = :quantity,
                 last_feed_id = :feed_id,
                 last_sync_status = :status,
                 last_sync_message = :message,
                 last_sync_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'quantity' => max(0, $quantity),
            'feed_id' => $feedId !== '' ? $feedId : null,
            'status' => 'success',
            'message' => $message,
        ]);
    }

    private function markRowAsError(int $id, string $status, string $message): void
    {
        $stmt = $this->appPdo->prepare(
            'UPDATE amazon_listing_inventory
             SET last_sync_status = :status,
                 last_sync_message = :message,
                 last_sync_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private function extractIssuesBySku(mixed $report, string $rawReport): array
    {
        $issuesBySku = [];

        $walker = function (mixed $node) use (&$walker, &$issuesBySku): void {
            if (!is_array($node)) {
                return;
            }

            $sku = trim((string) (
                $node['sku']
                ?? $node['sellerSku']
                ?? $node['seller_sku']
                ?? ''
            ));

            $severity = strtoupper(trim((string) (
                $node['severity']
                ?? $node['resultCode']
                ?? $node['level']
                ?? $node['type']
                ?? ''
            )));

            $messageParts = [];
            foreach (['code', 'messageCode', 'message', 'description', 'details', 'attributeName'] as $key) {
                if (isset($node[$key]) && trim((string) $node[$key]) !== '') {
                    $messageParts[] = trim((string) $node[$key]);
                }
            }

            if ($sku !== '' && ($severity !== '' || $messageParts !== [])) {
                $issuesBySku[$sku][] = [
                    'severity' => $severity !== '' ? $severity : 'INFO',
                    'message' => implode(' | ', array_unique($messageParts)),
                ];
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walker($value);
                }
            }
        };

        $walker($report);

        if ($issuesBySku !== []) {
            return $issuesBySku;
        }

        if ($rawReport !== '') {
            if (preg_match_all('/([A-Z0-9-]+-D\d+-H\d+).*?(ERROR|FATAL|WARNING|INFO).*?([\r\n]+)/i', $rawReport, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $sku = trim((string) ($match[1] ?? ''));
                    $severity = strtoupper(trim((string) ($match[2] ?? 'INFO')));
                    $message = trim((string) ($match[0] ?? ''));
                    if ($sku !== '') {
                        $issuesBySku[$sku][] = [
                            'severity' => $severity,
                            'message' => $message,
                        ];
                    }
                }
            }
        }

        return $issuesBySku;
    }

    private function hasHardError(array $issues): bool
    {
        foreach ($issues as $issue) {
            $severity = strtoupper(trim((string) ($issue['severity'] ?? '')));
            if (in_array($severity, ['ERROR', 'FATAL'], true)) {
                return true;
            }
        }

        return false;
    }

    private function buildIssueMessage(array $issues): string
    {
        $parts = [];
        foreach ($issues as $issue) {
            $severity = strtoupper(trim((string) ($issue['severity'] ?? 'INFO')));
            $message = trim((string) ($issue['message'] ?? ''));
            $parts[] = $message !== '' ? ('[' . $severity . '] ' . $message) : ('[' . $severity . ']');
        }

        return implode(' || ', array_unique($parts));
    }

    private function buildGeneralReportMessage(mixed $report, string $rawReport, string $downloadError): string
    {
        if ($downloadError !== '') {
            return 'Feed-Report konnte nicht geladen werden: ' . $downloadError;
        }

        if (is_array($report)) {
            $parts = [];

            foreach (['processingSummary', 'summary', 'header'] as $key) {
                if (!isset($report[$key]) || !is_array($report[$key])) {
                    continue;
                }

                foreach ($report[$key] as $subKey => $value) {
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $parts[] = $subKey . ': ' . trim((string) $value);
                    }
                }
            }

            if ($parts !== []) {
                return implode(' | ', array_unique($parts));
            }
        }

        $rawReport = trim($rawReport);
        if ($rawReport !== '') {
            $singleLine = preg_replace('/\s+/', ' ', $rawReport);
            if (is_string($singleLine) && $singleLine !== '') {
                return mb_substr($singleLine, 0, 500);
            }
        }

        return '';
    }
}

<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\AmazonService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$amazon = new AmazonService();

// IMPORTANT: SP-API expects marketplaceIds as array.
$marketplaceId = (string)($_ENV['AMAZON_MARKETPLACE_ID'] ?? '');
if ($marketplaceId === '') {
    http_response_code(500);
    echo "<pre>FEHLER: AMAZON_MARKETPLACE_ID fehlt in .env</pre>";
    exit;
}

// You can pass:
// ?q=ring
// ?q=ring,jewelry,wedding
$q = (string)($_GET['q'] ?? '');
$keywords = [];

if ($q !== '') {
    // allow comma-separated list
    $keywords = array_values(array_filter(array_map('trim', explode(',', $q))));
} else {
    // sensible defaults for jewelry/rings in DE marketplace
    $keywords = [
        'ring',
        'rings',
        'wedding',
        'wedding ring',
        'wedding band',
        'band',
        'jewelry',
        'jewellery',
        'fine jewelry',
        'engagement',
        'engagement ring',
        'trauring',
        'ehering',
        'schmuck',
    ];
}

echo "<pre>";
echo "Marketplace: {$marketplaceId}\n";
echo "Suche Product Types für Keywords:\n";
foreach ($keywords as $kw) {
    echo " - {$kw}\n";
}
echo "\n";

try {
    $all = []; // name => ['name'=>..., 'displayName'=>...]
    $hitsTotal = 0;

    foreach ($keywords as $keyword) {
        $result = $amazon->searchProductTypes($marketplaceId, $keyword);

        // Some implementations return ApiResponse->getPayload(), others return ProductTypeList directly.
        $payload = null;
        if (is_object($result) && method_exists($result, 'getPayload')) {
            $payload = $result->getPayload();
        } else {
            $payload = $result;
        }

        // payload should be ProductTypeList-like with getProductTypes()
        if (!is_object($payload) || !method_exists($payload, 'getProductTypes')) {
            echo "WARN: Unerwarteter Response-Typ bei Keyword '{$keyword}'.\n";
            echo "Class: " . (is_object($payload) ? get_class($payload) : gettype($payload)) . "\n\n";
            continue;
        }

        $types = $payload->getProductTypes();
        if (!is_array($types)) {
            // defensive
            $types = [];
        }

        $hitsTotal += count($types);

        foreach ($types as $type) {
            if (!is_object($type)) continue;

            $name = method_exists($type, 'getName') ? (string)$type->getName() : '';
            if ($name === '') continue;

            $display = method_exists($type, 'getDisplayName') ? (string)$type->getDisplayName() : '';

            $all[$name] = [
                'name' => $name,
                'displayName' => $display,
            ];
        }
    }

    ksort($all);

    echo "Gefundene Treffer (roh, inkl. Duplikate über Keywords): {$hitsTotal}\n";
    echo "Unique ProductTypes: " . count($all) . "\n\n";

    foreach ($all as $row) {
        echo "----------------------------------\n";
        echo "Name: " . $row['name'] . "\n";
        echo "Display Name: " . $row['displayName'] . "\n";
        echo "Definition testen:\n";
        echo "  /public/test_product_type.php?type=" . urlencode($row['name']) . "\n";
        echo "\n";
    }

} catch (\Throwable $e) {
    echo "FEHLER:\n";
    echo $e->getMessage() . "\n";
}

echo "</pre>";
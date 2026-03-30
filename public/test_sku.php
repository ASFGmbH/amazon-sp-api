<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\AmazonService;
use App\Services\MediaScanner;
use App\Services\StockService;
use App\Database\ProductPDO;
use App\Services\AmazonListingPayloadBuilder;
use App\Services\AmazonPriceService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$amazon = new AmazonService();
$stockService = new StockService();

$marketplaceId = (string)($_ENV['AMAZON_MARKETPLACE_ID'] ?? '');
$sellerId      = (string)($_ENV['AMAZON_SELLER_ID'] ?? '');

$model       = isset($_GET['model']) ? strtoupper(trim((string)$_GET['model'])) : 'E008';
$productType = isset($_GET['type']) ? strtoupper(trim((string)$_GET['type'])) : 'RING';
$locale      = isset($_GET['locale']) ? trim((string)$_GET['locale']) : 'en_US';
$ringSize    = isset($_GET['size']) ? trim((string)$_GET['size']) : '56';

// enable schema fragment dumps without affecting listing submission
$dumpSchema = isset($_GET['dumpSchema']) ? (int)$_GET['dumpSchema'] : 0;

echo "<pre>";
echo "=== SCRIPT VERSION: test_sku.php v8 (payload builder + price service) ===\n\n";

if ($marketplaceId === '' || $sellerId === '') {
    echo "FEHLER: AMAZON_MARKETPLACE_ID oder AMAZON_SELLER_ID fehlt in .env\n</pre>";
    exit;
}

/**
 * Reliable HTTP GET (Windows/XAMPP friendly), also supports weird pre-signed URLs.
 */
function http_get(string $url): string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 60,
            'header'  => "User-Agent: amazon-sp-api-local\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false && $data !== '') {
        return (string)$data;
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException("HTTP GET fehlgeschlagen und cURL ist nicht verfügbar.");
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'amazon-sp-api-local',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HEADER => false,
    ]);

    $out = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($out === false || $out === '') {
        throw new RuntimeException("HTTP GET via cURL fehlgeschlagen: {$err}");
    }
    if ($code >= 400) {
        throw new RuntimeException("HTTP GET via cURL HTTP {$code}: " . substr((string)$out, 0, 500));
    }

    return (string)$out;
}

/**
 * Load cached schema JSON downloaded from schema.link (test_product_type.php).
 */
function loadSchemaFromCache(string $productType, string $marketplaceId, string $locale): array
{
    $cacheDir = __DIR__ . '/../storage/schema-cache';
    $cacheFile = $cacheDir . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $productType . '-' . $marketplaceId . '-' . $locale) . '.json';

    if (!file_exists($cacheFile)) {
        throw new RuntimeException(
            "Schema-Cache fehlt: {$cacheFile}\n" .
            "Bitte zuerst ausführen:\n" .
            "  /public/test_product_type.php?type={$productType}&locale={$locale}\n"
        );
    }

    $schema = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($schema)) {
        throw new RuntimeException("Schema-Cache ist kein gültiges JSON: {$cacheFile}");
    }
    return $schema;
}

function jsonPointerGet(array $doc, string $pointer)
{
    if ($pointer === '' || $pointer === '#') {
        return $doc;
    }
    if (str_starts_with($pointer, '#')) {
        $pointer = substr($pointer, 1);
    }
    if ($pointer === '') {
        return $doc;
    }
    if (!str_starts_with($pointer, '/')) {
        return null;
    }

    $parts = explode('/', ltrim($pointer, '/'));
    $node = $doc;

    foreach ($parts as $p) {
        $p = str_replace(['~1', '~0'], ['/', '~'], $p);
        if (!is_array($node) || !array_key_exists($p, $node)) {
            return null;
        }
        $node = $node[$p];
    }

    return $node;
}

final class RefStore
{
    private string $dir;

    /** @var array<string, array> */
    private array $mem = [];

    public function __construct(string $dir)
    {
        $this->dir = $dir;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function load(string $url): array
    {
        if (isset($this->mem[$url])) {
            return $this->mem[$url];
        }

        $file = $this->dir . '/' . sha1($url) . '.json';
        if (!file_exists($file)) {
            $json = http_get($url);
            file_put_contents($file, $json);
        }

        $doc = json_decode((string)file_get_contents($file), true);
        if (!is_array($doc)) {
            throw new RuntimeException("Remote \$ref JSON ist ungültig: {$url}");
        }

        $this->mem[$url] = $doc;
        return $doc;
    }
}

function derefNode($node, array $root, RefStore $refs, int $depth = 0)
{
    if ($depth > 60) {
        return $node;
    }

    if (is_array($node) && isset($node['$ref']) && is_string($node['$ref'])) {
        $ref = $node['$ref'];

        if (str_starts_with($ref, '#')) {
            $target = jsonPointerGet($root, $ref);
            if ($target !== null) {
                return derefNode($target, $root, $refs, $depth + 1);
            }
            return $node;
        }

        if (preg_match('~^https?://~i', $ref)) {
            $url = $ref;
            $frag = '';
            $pos = strpos($ref, '#');
            if ($pos !== false) {
                $url = substr($ref, 0, $pos);
                $frag = substr($ref, $pos);
            }

            $doc = $refs->load($url);
            $target = $frag !== '' ? jsonPointerGet($doc, $frag) : $doc;
            if ($target !== null) {
                return derefNode($target, $doc, $refs, $depth + 1);
            }
            return $node;
        }
    }

    if (is_array($node) && isset($node['allOf']) && is_array($node['allOf'])) {
        if (count($node['allOf']) === 1) {
            return derefNode($node['allOf'][0], $root, $refs, $depth + 1);
        }
        return $node;
    }

    return $node;
}

function findAttributesNode(array $schema, RefStore $refs): ?array
{
    $queue = [['node' => $schema, 'path' => '$', 'root' => $schema]];
    $seen = 0;

    while ($queue) {
        $cur = array_shift($queue);
        $node = derefNode($cur['node'], $cur['root'], $refs);
        $path = $cur['path'];
        $root = $cur['root'];

        $seen++;
        if ($seen > 20000) {
            return null;
        }
        if (!is_array($node)) {
            continue;
        }

        if (isset($node['properties'], $node['properties']['attributes']) && is_array($node['properties'])) {
            $attrs = derefNode($node['properties']['attributes'], $root, $refs);
            if (is_array($attrs) && isset($attrs['properties']) && is_array($attrs['properties']) && count($attrs['properties']) > 0) {
                return [
                    'path' => $path . '.properties.attributes',
                    'attributesNode' => $attrs,
                    'attributesRoot' => $root,
                ];
            }
        }

        foreach ($node as $k => $v) {
            if (!is_array($v)) {
                continue;
            }

            if ($k === 'allOf') {
                foreach ($v as $i => $vv) {
                    if (is_array($vv)) {
                        $queue[] = ['node' => $vv, 'path' => $path . ".allOf[{$i}]", 'root' => $root];
                    }
                }
                continue;
            }

            $queue[] = ['node' => $v, 'path' => $path . '.' . (string)$k, 'root' => $root];
        }
    }

    return null;
}

/**
 * Search ANYWHERE in the deref graph for properties.<wantedKey>
 * Returns the first match with some context.
 */
function findPropertyDefinition(array $schema, RefStore $refs, string $wantedKey): ?array
{
    $queue = [['node' => $schema, 'path' => '$', 'root' => $schema]];
    $seen = 0;

    while ($queue) {
        $cur = array_shift($queue);
        $node = derefNode($cur['node'], $cur['root'], $refs);
        $path = $cur['path'];
        $root = $cur['root'];

        $seen++;
        if ($seen > 30000) {
            return null;
        }
        if (!is_array($node)) {
            continue;
        }

        if (isset($node['properties']) && is_array($node['properties']) && array_key_exists($wantedKey, $node['properties'])) {
            $def = derefNode($node['properties'][$wantedKey], $root, $refs);
            if (is_array($def)) {
                return [
                    'path' => $path . ".properties.{$wantedKey}",
                    'definition' => $def,
                ];
            }
        }

        foreach ($node as $k => $v) {
            if (!is_array($v)) {
                continue;
            }

            if ($k === 'allOf') {
                foreach ($v as $i => $vv) {
                    if (is_array($vv)) {
                        $queue[] = ['node' => $vv, 'path' => $path . ".allOf[{$i}]", 'root' => $root];
                    }
                }
                continue;
            }

            $queue[] = ['node' => $v, 'path' => $path . '.' . (string)$k, 'root' => $root];
        }
    }

    return null;
}

/** Pretty print a schema fragment with keys we care about (required, enum, items, properties). */
function dumpSchemaFragment(string $label, array $hit): void
{
    echo "=============================\n";
    echo "SCHEMA FRAGMENT: {$label}\n";
    echo "=============================\n";
    echo "Found at: " . ($hit['path'] ?? '(unknown)') . "\n";

    $def = $hit['definition'] ?? null;
    if (!is_array($def)) {
        echo "(no definition)\n\n";
        return;
    }

    $out = [
        'title' => $def['title'] ?? null,
        'description' => $def['description'] ?? null,
        'type' => $def['type'] ?? null,
        'minItems' => $def['minItems'] ?? null,
        'required' => $def['required'] ?? null,
        'enum' => $def['enum'] ?? null,
        'items.type' => $def['items']['type'] ?? null,
        'items.required' => $def['items']['required'] ?? null,
        'items.properties' => array_keys($def['items']['properties'] ?? []),
        'properties' => array_keys($def['properties'] ?? []),
    ];

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    if (isset($def['items']['properties']) && is_array($def['items']['properties'])) {
        echo "---- items.properties (enum hints) ----\n";
        foreach ($def['items']['properties'] as $k => $p) {
            if (!is_array($p)) {
                continue;
            }
            $enum = $p['enum'] ?? null;
            $req  = $p['minItems'] ?? null;

            echo "- {$k}";
            if ($req !== null) {
                echo " (minItems: {$req})";
            }

            if (is_array($enum)) {
                $sample = array_slice($enum, 0, 30);
                echo " enum: " . implode(', ', array_map('strval', $sample)) . (count($enum) > 30 ? " ..." : "");
            }
            echo "\n";
        }
        echo "\n";
    }

    if (isset($def['properties']) && is_array($def['properties'])) {
        echo "---- properties (enum hints) ----\n";
        foreach ($def['properties'] as $k => $p) {
            if (!is_array($p)) {
                continue;
            }
            $enum = $p['enum'] ?? null;
            $req  = $p['minItems'] ?? null;

            echo "- {$k}";
            if ($req !== null) {
                echo " (minItems: {$req})";
            }
            if (is_array($enum)) {
                $sample = array_slice($enum, 0, 30);
                echo " enum: " . implode(', ', array_map('strval', $sample)) . (count($enum) > 30 ? " ..." : "");
            }
            echo "\n";
        }
        echo "\n";
    }

    if (isset($def['items']['properties']) && is_array($def['items']['properties'])) {
        echo "---- items.properties (detailed) ----\n";
        foreach ($def['items']['properties'] as $k => $p) {
            if (!is_array($p)) {
                echo "- {$k}: (not an array)\n";
                continue;
            }

            $row = [
                'type' => $p['type'] ?? null,
                'minItems' => $p['minItems'] ?? null,
                'required' => $p['required'] ?? null,
                'enum' => $p['enum'] ?? null,
                'items.type' => $p['items']['type'] ?? null,
                'items.properties' => isset($p['items']['properties']) && is_array($p['items']['properties'])
                    ? array_keys($p['items']['properties'])
                    : [],
            ];

            echo "- {$k}: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
        echo "\n";
    }
}

function indexAttributes(array $attributesRoot, array $attributesNode, RefStore $refs): array
{
    $props = $attributesNode['properties'] ?? null;
    if (!is_array($props)) {
        return [];
    }

    $idx = [];
    foreach ($props as $key => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        $cfg = derefNode($cfg, $attributesRoot, $refs);
        $idx[(string)$key] = [
            'title' => isset($cfg['title']) ? trim((string)$cfg['title']) : '',
            'description' => isset($cfg['description']) ? trim((string)$cfg['description']) : '',
            'type' => isset($cfg['type']) ? trim((string)$cfg['type']) : '',
            'minItems' => $cfg['minItems'] ?? null,
            'itemsRequired' => (isset($cfg['items']['required']) && is_array($cfg['items']['required']))
                ? array_values(array_map('strval', $cfg['items']['required']))
                : [],
            'enum' => (isset($cfg['enum']) && is_array($cfg['enum'])) ? $cfg['enum'] : null,
        ];
    }

    ksort($idx);
    return $idx;
}

function extractIssuesVerbose($response): array
{
    $payload = (is_object($response) && method_exists($response, 'getPayload')) ? $response->getPayload() : $response;
    if (!$payload || !is_object($payload) || !method_exists($payload, 'getIssues')) {
        return [];
    }

    $issues = $payload->getIssues();
    if (!$issues) {
        return [];
    }

    $out = [];
    foreach ($issues as $issue) {
        $row = [
            'severity' => method_exists($issue, 'getSeverity') ? (string)$issue->getSeverity() : '',
            'code' => method_exists($issue, 'getCode') ? (string)$issue->getCode() : '',
            'message' => method_exists($issue, 'getMessage') ? (string)$issue->getMessage() : '',
            'attributeName' => method_exists($issue, 'getAttributeName') ? (string)$issue->getAttributeName() : '',
        ];

        foreach ([
                     'getAttributeNames' => 'attributeNames',
                     'getAttributePath'  => 'attributePath',
                     'getAttributes'     => 'attributes',
                     'getDetails'        => 'details',
                 ] as $getter => $key) {
            if (method_exists($issue, $getter)) {
                $val = $issue->{$getter}();
                $row[$key] = json_decode(json_encode($val), true);
            }
        }

        $row['_raw'] = json_decode(json_encode($issue), true);
        $out[] = $row;
    }

    return $out;
}

function printIssuesVerbose(array $issues, string $label): void
{
    echo "=============================\n";
    echo $label . "\n";
    echo "=============================\n";
    if (!$issues) {
        echo "(Keine issues)\n\n";
        return;
    }

    foreach ($issues as $i) {
        $sev = $i['severity'] ?: 'INFO';
        $code = $i['code'] ?: '';
        $msg  = $i['message'] ?: '';
        $attrNames = $i['attributeNames'] ?? null;
        $attrPath  = $i['attributePath'] ?? null;

        echo "- [{$sev}] {$code}: {$msg}";
        if (is_array($attrNames) && $attrNames) {
            echo " (attributeNames: " . implode(', ', array_map('strval', $attrNames)) . ")";
        }
        if (is_string($attrPath) && $attrPath !== '') {
            echo " (attributePath: {$attrPath})";
        }
        echo "\n";
    }
    echo "\n";
}

function printIssuesRawJson(array $issues): void
{
    echo "=============================\n";
    echo "RAW ISSUES JSON (für Key-Mapping)\n";
    echo "=============================\n";
    echo json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}

function extractGermanLabelFromIssueMessage(string $msg): ?string
{
    if (preg_match('/„([^“]+)“/u', $msg, $m)) {
        return trim((string)$m[1]);
    }
    return null;
}

function fetchPropertyGroupsFallback(AmazonService $amazon, string $productType, string $marketplaceId, string $sellerId, string $locale): array
{
    $ref = new ReflectionObject($amazon);
    $prop = $ref->getProperty('definitionsApi');
    $prop->setAccessible(true);
    $api = $prop->getValue($amazon);

    $res = $api->getDefinitionsProductType(
        $productType,
        $marketplaceId,
        $sellerId,
        'LATEST',
        'LISTING',
        'ENFORCED',
        $locale
    );

    $payload = (is_object($res) && method_exists($res, 'getPayload')) ? $res->getPayload() : null;
    if (!$payload) {
        return [];
    }

    if (is_object($payload) && method_exists($payload, 'getPropertyGroups')) {
        $pg = $payload->getPropertyGroups();
        $arr = json_decode(json_encode($pg), true);
        return is_array($arr) ? $arr : [];
    }

    $arr = json_decode(json_encode($payload), true);
    if (is_array($arr) && isset($arr['propertyGroups']) && is_array($arr['propertyGroups'])) {
        return $arr['propertyGroups'];
    }

    return [];
}

function getLocalModelImages(string $model): array
{
    $scanner = new MediaScanner();
    $models  = $scanner->scan(__DIR__ . '/../media');

    foreach ($models as $m) {
        if (strtoupper((string)$m['model']) === strtoupper($model)) {
            return [
                'main' => $m['main'] ?? null,
                'gallery' => $m['gallery'] ?? [],
            ];
        }
    }

    return ['main' => null, 'gallery' => []];
}

function getProductRowByModel(string $model): ?array
{
    $pdo = null;

    if (class_exists(ProductPDO::class)) {
        if (method_exists(ProductPDO::class, 'get')) {
            $pdo = ProductPDO::get();
        } else {
            $obj = new ProductPDO();
            if (method_exists($obj, 'pdo')) {
                $pdo = $obj->pdo();
            } elseif ($obj instanceof PDO) {
                $pdo = $obj;
            }
        }
    }

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('ProductPDO: Konnte keine PDO-Instanz bekommen (ProductPDO::get() / ->pdo() fehlt?).');
    }

    $stmt = $pdo->prepare("SELECT * FROM `object_query_1` WHERE customfield_asf_model = :model LIMIT 1");
    $stmt->execute(['model' => $model]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function getProductPdo(): PDO
{
    $pdo = null;

    if (class_exists(ProductPDO::class)) {
        if (method_exists(ProductPDO::class, 'get')) {
            $pdo = ProductPDO::get();
        } else {
            $obj = new ProductPDO();
            if (method_exists($obj, 'pdo')) {
                $pdo = $obj->pdo();
            } elseif ($obj instanceof PDO) {
                $pdo = $obj;
            }
        }
    }

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('ProductPDO: Konnte keine PDO-Instanz für AmazonPriceService bekommen.');
    }

    return $pdo;
}

function parse_materials(string $raw): array
{
    $raw = trim(mb_strtolower($raw));
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\-\/\+_,]+/u', $raw) ?: [];
    $map = [
        'edelstahl' => 'Edelstahl',
        'carbon' => 'Carbon',
        'karbon' => 'Carbon',
        'holz' => 'Holz',
        'titan' => 'Titan',
        'stahl' => 'Stahl',
    ];

    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $out[] = $map[$p] ?? mb_convert_case($p, MB_CASE_TITLE, "UTF-8");
    }

    return array_values(array_unique($out));
}

function parse_stones(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^\s*(\d+)\s*x\s*([0-9]*\.?[0-9]+)\s*$/i', $raw, $m)) {
        return null;
    }

    $count = (int)$m[1];
    $each = (float)$m[2];
    $total = $count * $each;

    return ['count' => $count, 'carat_each' => $each, 'carat_total' => $total];
}

function parse_list(string $raw, string $sep = ';'): array
{
    $parts = array_map('trim', explode($sep, $raw));
    return array_values(array_filter($parts, static fn($v) => $v !== ''));
}

function surface_label(?string $code): ?string
{
    if ($code === null) {
        return null;
    }
    $code = trim(mb_strtolower($code));
    if ($code === '') {
        return null;
    }

    $map = [
        'po' => 'poliert',
        'lm' => 'längs-matt',
        'sam' => 'sand-matt',
        'grsam' => 'grob-sand-matt',
        'qm' => 'quer-matt',
        'scm' => 'schräg-matt',
        'em' => 'eis-matt',
        'xm' => 'x-matt',
        'hpo' => 'hammer-poliert',
        'hma' => 'hammer-matt',
        'krlm' => 'kratz-längs-matt',
        'xms' => 'x-matt-stark',
    ];

    return $map[$code] ?? $code;
}

function getModelTextDataFallback(string $model): array
{
    return [
        'title' => "Partnerring/Freundschaftsring {$model}",
        'brand' => "ASF-Trauringe",
        'description' => "Modell {$model} – Partnerring/Freundschaftsring.",
        'bullets' => [
            "Partnerring Modell {$model}",
            "Hochwertige Verarbeitung",
            "Größen Damen/Herren wählbar",
        ],
        'price' => 129.00,
        'part_number' => $model,
    ];
}

/**
 * Pick merchant_suggested_asin based on materials.
 */
function pickSuggestedAsin(array $materials): string
{
    $asinTitan  = 'B0F13ZQ1SZ';
    $asinCarbon = 'B0GP6VZ1ZV';
    $asinWood   = 'B0GQ3N4328';

    $m = array_map('mb_strtolower', $materials);

    if (in_array('carbon', $m, true)) {
        return $asinCarbon;
    }
    if (in_array('holz', $m, true)) {
        return $asinWood;
    }
    if (in_array('titan', $m, true)) {
        return $asinTitan;
    }

    return $asinCarbon;
}

echo "MODEL: {$model}\n";
echo "Marketplace: {$marketplaceId}\n";
echo "SellerId: {$sellerId}\n";
echo "ProductType: {$productType}\n";
echo "Locale: {$locale}\n";
echo "Requested size: {$ringSize}\n\n";

$imgs = getLocalModelImages($model);

echo "Images (local paths):\n";
echo "- main: " . ($imgs['main'] ?: '(none)') . "\n";
echo "- gallery: " . count($imgs['gallery']) . "\n\n";

$productRow = null;
$normalized = null;

if ($imgs['main'] !== null) {
    try {
        $productRow = getProductRowByModel($model);
    } catch (Throwable $e) {
        echo "WARNUNG: ProductPDO Lookup fehlgeschlagen: {$e->getMessage()}\n\n";
    }
}

if (is_array($productRow)) {
    $materials = parse_materials((string)($productRow['customfield_asf_material'] ?? ''));
    $stones    = parse_stones((string)($productRow['customfield_asf_stones'] ?? ''));
    $stoneType = trim((string)($productRow['customfield_asf_default_stone'] ?? 'Zirkonia')) ?: 'Zirkonia';
    $stoneCut  = trim((string)($productRow['customfield_asf_ground'] ?? '')) ?: '';
    $stoneColors = parse_list((string)($productRow['customfield_asf_stone_colors'] ?? ''));
    $surface1 = surface_label($productRow['customfield_asf_surface'] ?? null);
    $surface2 = surface_label($productRow['customfield_asf_second_surface'] ?? null);

    $normalized = [
        'oo_id' => (string)($productRow['oo_id'] ?? ''),
        'model' => (string)($productRow['customfield_asf_model'] ?? $model),
        'manufacturer' => (string)($productRow['manufacturer'] ?? ''),
        'type_keyword' => (string)($productRow['customfield_asf_type'] ?? ''),
        'desc' => (string)($productRow['desc'] ?? ''),
        'ean' => trim((string)($productRow['ean'] ?? '')),
        'materials' => $materials,
        'materials_display' => $materials ? implode('-', $materials) : '',
        'stones' => $stones,
        'stone_type' => $stoneType,
        'stone_cut' => $stoneCut,
        'stone_colors' => $stoneColors,
        'surface_1' => $surface1,
        'surface_2' => $surface2,
        'jewelry_categorization' => 'fashion',
        'color' => 'silver',
        'department' => 'unisex-adult',
        'dg_regulation' => 'not_applicable',
        'suggested_asin' => pickSuggestedAsin($materials),
    ];

    echo "=============================\n";
    echo "PRODUCTPDO ROW FOUND\n";
    echo "=============================\n";
    echo "oo_id: " . ($normalized['oo_id'] ?: '(none)') . "\n";
    echo "materials_norm: " . json_encode($normalized['materials'], JSON_UNESCAPED_UNICODE) . "\n";
    echo "stones_norm: " . json_encode($normalized['stones'], JSON_UNESCAPED_UNICODE) . "\n";
    echo "suggested_asin: {$normalized['suggested_asin']}\n\n";
} else {
    echo "INFO: Kein ProductPDO-Datensatz (oder kein main image gefunden) → nutze Fallback Texte.\n\n";
}

// --- Schema + refs ---
$schema = loadSchemaFromCache($productType, $marketplaceId, $locale);
$refs = new RefStore(__DIR__ . '/../storage/schema-refs');

// targeted schema fragment dumps (stones + ring)
$hitStones = findPropertyDefinition($schema, $refs, 'stones');
if ($hitStones) {
    dumpSchemaFragment('stones', $hitStones);
} else {
    echo "SCHEMA: stones not found\n\n";
}

$hitRing = findPropertyDefinition($schema, $refs, 'ring');
if ($hitRing) {
    dumpSchemaFragment('ring', $hitRing);
} else {
    echo "SCHEMA: ring not found\n\n";
}

// Optional: stop here if dumpSchema=1 (no API call / no listing submission)
if ($dumpSchema === 1) {
    echo "dumpSchema=1 => Stop after schema fragments (no listing submission).\n";
    echo "</pre>";
    exit;
}

// --- propertyGroups (optional) ---
$pg = [];
try {
    $pg = fetchPropertyGroupsFallback($amazon, $productType, $marketplaceId, $sellerId, $locale);
} catch (Throwable $e) {
    echo "WARNUNG: propertyGroups konnten nicht geladen werden: {$e->getMessage()}\n\n";
}

echo "=============================\n";
echo "PROPERTY GROUPS (Definition Payload)\n";
echo "=============================\n\n";

if (!$pg) {
    echo "(Keine propertyGroups erhalten oder nicht lesbar.)\n\n";
} else {
    foreach ($pg as $groupName => $cfg) {
        $title = $cfg['title'] ?? '';
        $names = $cfg['propertyNames'] ?? [];
        echo "- {$groupName}" . ($title ? " ({$title})" : "") . "\n";
        if (is_array($names)) {
            foreach ($names as $n) {
                echo "    • {$n}\n";
            }
        }
        echo "\n";
    }
}

// --- Stock / qty ---
$stock = $stockService->getStructuredStock($model);
$qty = max(0, (int)($stock['total'] ?? 0));

// --- Texts ---
$text = getModelTextDataFallback($model);

if (is_array($normalized)) {
    $brand = trim($normalized['manufacturer']) !== '' ? $normalized['manufacturer'] : $text['brand'];
    $desc  = trim($normalized['desc']) !== '' ? $normalized['desc'] : $text['description'];
    $title = trim($normalized['type_keyword']) !== '' ? $normalized['type_keyword'] . " " . $model : $text['title'];

    $text['brand'] = $brand;
    $text['description'] = $desc;
    $text['title'] = $title;
    $text['part_number'] = $model;
}

// --- SKU building (oo_id + size = unique) ---
$baseId = is_array($normalized) && ($normalized['oo_id'] ?? '') !== ''
    ? (string)$normalized['oo_id']
    : $model;
$sellerSku = "{$baseId}-{$ringSize}";

echo "Sende SINGLE SKU Listing: {$sellerSku} (model {$model})\n\n";

// --- Price service ---
try {
    $priceService = new AmazonPriceService(getProductPdo());

    $basePrice = (float)$text['price'];
    $priceGroup = $priceService->detectPriceGroupFromNormalizedData($normalized);
    $priceInfo = $priceService->calculatePriceBreakdown($basePrice, $priceGroup, true);
    $finalPrice = (float)$priceInfo['final_gross'];

    echo "=============================\n";
    echo "PRICE BREAKDOWN\n";
    echo "=============================\n";
    echo json_encode($priceInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
} catch (Throwable $e) {
    echo "WARNUNG: AmazonPriceService fehlgeschlagen, nutze Fallback-Preis. {$e->getMessage()}\n\n";
    $priceInfo = null;
    $finalPrice = (float)$text['price'];
}

$materials = is_array($normalized) ? ($normalized['materials'] ?? []) : [];
$suggestedAsin = is_array($normalized)
    ? ($normalized['suggested_asin'] ?? pickSuggestedAsin($materials))
    : pickSuggestedAsin($materials);

$stoneCount = (int)(
is_array($normalized) && is_array($normalized['stones'] ?? null)
    ? ($normalized['stones']['count'] ?? 0)
    : 0
);
if ($stoneCount < 1) {
    $stoneCount = 1;
}

$gemTypeValue = 'Zirkonia';
if (is_array($normalized) && !empty($normalized['stone_type'])) {
    $gemTypeValue = (string)$normalized['stone_type'];
}

$builder = (new AmazonListingPayloadBuilder())
    ->setSellerSku($sellerSku)
    ->setProductType($productType)
    ->setRequirements('LISTING')
    ->setMarketplaceId($marketplaceId)
    ->setLanguageTag('de_DE')
    ->setCurrency('EUR')
    ->setItemName($text['title'] . " Größe {$ringSize}")
    ->setBrand($text['brand'])
    ->setProductDescription((string)$text['description'])
    ->setBulletPoints((array)$text['bullets'])
    ->setPrice($finalPrice)
    ->setListPrice($finalPrice)
    ->setQuantity($qty)
    ->setPartNumber($text['part_number'])
    ->setSupplierDeclaredDgHzRegulation('not_applicable')
    ->setJewelryMaterialCategorization('fashion')
    ->setDepartment('unisex-adult')
    ->setColor('silver')
    ->setGemType($gemTypeValue)
    ->setRingSize((string)$ringSize)
    ->setCountryOfOrigin('CN')
    ->setIsResizable(false)
    ->setMerchantSuggestedAsin((string)$suggestedAsin)
    ->setRecommendedBrowseNode(11961464031)
    ->setStones(
        $stoneCount,
        'cubic_zirconia',
        'lab_created',
        'not_treated'
    );

if (is_array($normalized) && ($normalized['ean'] ?? '') !== '') {
    $builder->setExternalProductId((string)$normalized['ean']);
}

if (is_array($normalized) && !empty($normalized['materials_display'])) {
    $builder->setMaterial((string)$normalized['materials_display']);
} elseif (is_array($normalized) && !empty($normalized['materials'])) {
    $builder->setMaterial((string)$normalized['materials'][0]);
}

if (is_array($normalized)) {
    $s1 = !empty($normalized['surface_1']) ? "Oberfläche: {$normalized['surface_1']}" : null;
    $s2 = !empty($normalized['surface_2']) ? "Zweite Oberfläche: {$normalized['surface_2']}" : null;
    $extraBullets = array_values(array_filter([$s1, $s2], static fn($v) => is_string($v) && $v !== ''));

    foreach ($extraBullets as $bullet) {
        $builder->addBulletPoint($bullet);
    }
}

$requestData = $builder->build();

echo "=============================\n";
echo "FINAL PAYLOAD\n";
echo "=============================\n";
echo json_encode($requestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

try {
    $resp = $amazon->createOrUpdateListing($requestData);

    $issues = extractIssuesVerbose($resp);
    printIssuesVerbose($issues, "LISTING RESPONSE / ISSUES ({$sellerSku})");
    printIssuesRawJson($issues);

    $missingLabels = [];
    foreach ($issues as $i) {
        if (($i['code'] ?? '') === '90220') {
            $label = extractGermanLabelFromIssueMessage((string)($i['message'] ?? ''));
            if ($label) {
                $missingLabels[] = $label;
            }
        }
    }
    $missingLabels = array_values(array_unique($missingLabels));

    echo "=============================\n";
    echo "WAS AMAZON WIRKLICH VERLANGT (aus Issues)\n";
    echo "=============================\n\n";
    foreach ($missingLabels as $l) {
        echo "• {$l}\n";
    }
    echo "\n";

    echo "OK.\n";
} catch (Throwable $e) {
    echo "FEHLER beim Senden: " . $e->getMessage() . "\n\n";
}

echo "</pre>";
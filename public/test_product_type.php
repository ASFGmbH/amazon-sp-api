<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\AmazonService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$amazon = new AmazonService();

$marketplaceId = (string)($_ENV['AMAZON_MARKETPLACE_ID'] ?? '');
$sellerId      = (string)($_ENV['AMAZON_SELLER_ID'] ?? '');
$productType   = (string)($_GET['type'] ?? 'RING');
$locale        = isset($_GET['locale']) ? trim((string)$_GET['locale']) : null;
$productTypeVersion = isset($_GET['version']) ? trim((string)$_GET['version']) : 'LATEST';

$requirements = 'LISTING';
$enforced     = 'ENFORCED';

function http_get(string $url): string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 60,
            'header'  => "User-Agent: amazon-sp-api-local\r\n",
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false && $data !== '') return (string)$data;

    if (!function_exists('curl_init')) {
        throw new Exception("HTTP GET fehlgeschlagen (file_get_contents) und cURL ist nicht verfügbar.");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'amazon-sp-api-local',
    ]);

    $out  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($out === false || $out === '') throw new Exception("HTTP GET via cURL fehlgeschlagen: {$err}");
    if ($code >= 400) throw new Exception("HTTP GET via cURL HTTP {$code}: " . substr((string)$out, 0, 500));

    return (string)$out;
}

function extract_schema_url($node): ?string
{
    if ($node === null) return null;

    if (is_string($node) && $node !== '') {
        if (preg_match('~^https?://~i', $node)) return $node;
        $j = json_decode($node, true);
        if (is_array($j)) return extract_schema_url($j);
        return null;
    }

    if (is_object($node)) {
        if (method_exists($node, 'getResource')) {
            $u = (string)$node->getResource();
            if ($u !== '' && preg_match('~^https?://~i', $u)) return $u;
        }
        if (method_exists($node, 'getLink')) {
            $u = $node->getLink();
            $found = extract_schema_url($u);
            if ($found) return $found;
        }
        return extract_schema_url((array)$node);
    }

    if (is_array($node)) {
        foreach (['resource', 'link', 'url'] as $k) {
            if (isset($node[$k])) {
                $found = extract_schema_url($node[$k]);
                if ($found) return $found;
            }
        }
        foreach ($node as $v) {
            $found = extract_schema_url($v);
            if ($found) return $found;
        }
    }

    return null;
}

/**
 * Recursively search schema for a node that looks like:
 *   ['properties' => ['attributes' => ['properties' => [...]]]]
 * Return: ['path' => '...', 'attributesNode' => array]
 */
function find_attributes_node(array $schema): ?array
{
    $queue = [[
        'node' => $schema,
        'path' => '$'
    ]];

    while ($queue) {
        $cur = array_shift($queue);
        $node = $cur['node'];
        $path = $cur['path'];

        if (is_array($node)) {
            // direct hit: properties.attributes.properties
            if (isset($node['properties']) && is_array($node['properties'])
                && isset($node['properties']['attributes']) && is_array($node['properties']['attributes'])
                && isset($node['properties']['attributes']['properties']) && is_array($node['properties']['attributes']['properties'])
            ) {
                return [
                    'path' => $path . '.properties.attributes',
                    'attributesNode' => $node['properties']['attributes'],
                ];
            }

            // sometimes attributes is defined via $ref within properties.attributes
            if (isset($node['properties']) && is_array($node['properties'])
                && isset($node['properties']['attributes']) && is_array($node['properties']['attributes'])
            ) {
                $attrs = $node['properties']['attributes'];
                if (isset($attrs['properties']) && is_array($attrs['properties'])) {
                    return [
                        'path' => $path . '.properties.attributes',
                        'attributesNode' => $attrs,
                    ];
                }
            }

            // BFS over children
            foreach ($node as $k => $v) {
                if (is_array($v)) {
                    $queue[] = [
                        'node' => $v,
                        'path' => $path . '.' . (string)$k,
                    ];
                }
            }
        }
    }

    return null;
}

/**
 * Build attribute index from an attributes node: attributes.properties[*]
 */
function index_attributes_from_attributes_node(array $attributesNode): array
{
    $props = $attributesNode['properties'] ?? null;
    if (!is_array($props)) return [];

    $idx = [];
    foreach ($props as $key => $cfg) {
        if (!is_array($cfg)) continue;

        $idx[(string)$key] = [
            'title' => isset($cfg['title']) ? (string)$cfg['title'] : '',
            'description' => isset($cfg['description']) ? (string)$cfg['description'] : '',
            'type' => isset($cfg['type']) ? (string)$cfg['type'] : '',
            'minItems' => $cfg['minItems'] ?? null,
            'maxItems' => $cfg['maxItems'] ?? null,
            'items_required' => (isset($cfg['items']['required']) && is_array($cfg['items']['required']))
                ? array_values(array_map('strval', $cfg['items']['required']))
                : [],
            'enum' => (isset($cfg['enum']) && is_array($cfg['enum'])) ? $cfg['enum'] : null,
        ];
    }
    ksort($idx);
    return $idx;
}

echo "<pre>";
echo "Lade Product Type Definition für: {$productType}\n";
echo "Marketplace: {$marketplaceId}\n";
echo "SellerId: " . ($sellerId ?: '(missing)') . "\n";
echo "Locale: " . ($locale ?: '(none)') . "\n";
echo "productTypeVersion: {$productTypeVersion}\n\n";

if ($marketplaceId === '') { echo "FEHLER: AMAZON_MARKETPLACE_ID fehlt in .env\n</pre>"; exit; }
if ($sellerId === '') { echo "FEHLER: AMAZON_SELLER_ID fehlt in .env\n</pre>"; exit; }

try {
    // access definitionsApi from AmazonService (private)
    $ref = new ReflectionObject($amazon);
    $prop = $ref->getProperty('definitionsApi');
    $prop->setAccessible(true);
    $api = $prop->getValue($amazon);

    $marketplaceIds = $marketplaceId; // string

    echo "=== Call Args ===\n";
    echo "product_type: {$productType}\n";
    echo "marketplace_ids: {$marketplaceIds}\n";
    echo "seller_id: {$sellerId}\n";
    echo "product_type_version: {$productTypeVersion}\n";
    echo "requirements: {$requirements}\n";
    echo "requirements_enforced: {$enforced}\n";
    echo "locale: " . ($locale ?: '(none)') . "\n\n";

    $res = $api->getDefinitionsProductType(
        $productType,
        $marketplaceIds,
        $sellerId,
        $productTypeVersion,
        $requirements,
        $enforced,
        ($locale !== null && $locale !== '') ? $locale : null
    );

    $payload = (is_object($res) && method_exists($res, 'getPayload')) ? $res->getPayload() : $res;
    if (!$payload || !is_object($payload)) throw new Exception('Keine Payload erhalten.');

    // ---- propertyGroups dump (often the real list of attribute keys!) ----
    echo "=============================\n";
    echo "PROPERTY GROUPS (aus Definition-Payload)\n";
    echo "=============================\n\n";

    if (method_exists($payload, 'getPropertyGroups')) {
        $pg = $payload->getPropertyGroups();
        // jlevers models sometimes return array-like
        $pgArr = json_decode(json_encode($pg), true);

        if (is_array($pgArr) && $pgArr) {
            foreach ($pgArr as $groupName => $groupCfg) {
                $title = $groupCfg['title'] ?? '';
                $attrs = $groupCfg['propertyNames'] ?? [];
                echo "- {$groupName}" . ($title ? " ({$title})" : "") . "\n";
                if (is_array($attrs)) {
                    foreach ($attrs as $a) echo "    • {$a}\n";
                }
                echo "\n";
            }
        } else {
            echo "(Keine propertyGroups oder nicht lesbar)\n\n";
        }
    } else {
        echo "(payload->getPropertyGroups() nicht verfügbar in deiner SDK-Modelklasse)\n\n";
    }

    // ---- schema link ----
    $schemaRaw = method_exists($payload, 'getSchema') ? $payload->getSchema() : null;

    echo "=== Schema URL ===\n";
    $schemaUrl = extract_schema_url($schemaRaw);
    echo ($schemaUrl ?: '(none)') . "\n\n";
    if (!$schemaUrl) throw new Exception('Konnte keine Schema-URL aus payload->schema extrahieren.');

    // ---- cache per locale ----
    $cacheDir = __DIR__ . '/../storage/schema-cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

    $locSuffix = ($locale !== null && $locale !== '') ? $locale : 'none';
    $cacheFile = $cacheDir . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $productType . '-' . $marketplaceId . '-' . $locSuffix) . '.json';

    if (!file_exists($cacheFile)) {
        $json = http_get($schemaUrl);
        file_put_contents($cacheFile, $json);
    }

    $schema = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($schema)) throw new Exception('Schema JSON ist ungültig.');

    // ---- find attributes node anywhere ----
    $hit = find_attributes_node($schema);

    echo "=============================\n";
    echo "SCHEMA ATTRIBUTES NODE\n";
    echo "=============================\n\n";

    if (!$hit) {
        echo "❌ Konnte im Schema keinen attributes-Block finden.\n";
        echo "Das bedeutet: Das Schema arbeitet vermutlich stark mit \$ref/definitions.\n";
        echo "Dann müssen wir als nächsten Schritt \$ref resolven (remote laden) oder metaSchemas prüfen.\n\n";
        echo "Cache:\n{$cacheFile}\n\n";
        echo "OK.\n</pre>";
        exit;
    }

    echo "✅ Gefunden unter Pfad:\n" . $hit['path'] . "\n\n";

    $attrIndex = index_attributes_from_attributes_node($hit['attributesNode']);
    echo "Gefundene Attribute-Keys: " . count($attrIndex) . "\n\n";

    // Print compact list + shape hints
    $i = 0;
    foreach ($attrIndex as $key => $meta) {
        $i++;
        $title = trim((string)($meta['title'] ?? ''));
        $type  = trim((string)($meta['type'] ?? ''));
        $minItems = $meta['minItems'];
        $itemsReq = $meta['items_required'] ?? [];

        echo "---------------------------------\n";
        echo "KEY: {$key}\n";
        if ($title !== '') echo "Title: {$title}\n";
        if ($type !== '') echo "Type: {$type}\n";
        if ($minItems !== null) echo "minItems: {$minItems}\n";
        if ($itemsReq) echo "items.required: " . implode(', ', $itemsReq) . "\n";

        // enum snippet
        if (is_array($meta['enum'])) {
            $vals = array_slice($meta['enum'], 0, 30);
            echo "Enum (Auszug): " . implode(', ', array_map('strval', $vals)) . (count($meta['enum']) > 30 ? " ..." : "") . "\n";
        }

        // typical listings shape hint
        echo "Payload-Shape (typisch):\n";
        echo "  \"{$key}\": [ { \"value\": ..., \"marketplace_id\": \"{$marketplaceId}\" } ]\n\n";

        if ($i >= 120) {
            echo "(Ausgabe gekürzt nach 120 Keys…)\n\n";
            break;
        }
    }

    echo "=============================\n";
    echo "CACHE\n";
    echo "=============================\n\n";
    echo "Gespeichert als:\n{$cacheFile}\n\n";

    echo "OK.\n";

} catch (Throwable $e) {
    echo "FEHLER:\n" . $e->getMessage() . "\n";
}

echo "</pre>";
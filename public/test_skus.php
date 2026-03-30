<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\ProductPDO;


$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

/*
|--------------------------------------------------------------------------
| 3️⃣ DB TEST (unverändert)
|--------------------------------------------------------------------------
*/

$sku = '49859';
$tables = ['object_query_1'];
$pdo = ProductPDO::get();

$results = [];

foreach ($tables as $table) {
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE oo_id = :sku OR customfield_asf_model = 'S005'");
    $stmt->execute(['sku' => $sku]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $results[$table] = $rows;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>SKU Test</title>
    <style>
        body { font-family: monospace; background:#111; color:#eee; padding:20px; }
        pre { background:#222; padding:15px; border-radius:8px; overflow-x:auto; }
        h1 { color:#0af; }
        .table-name { color:#0f0; font-weight:bold; margin-top:20px; }
    </style>
</head>
<body>
<h1>Test: SKU <?= htmlspecialchars($sku) ?></h1>

<?php if (!empty($results)): ?>
    <?php foreach ($results as $table => $rows): ?>
        <div class="table-name">Gefunden in Tabelle: <?= htmlspecialchars($table) ?></div>
        <?php foreach ($rows as $row): ?>
            <pre><?= print_r($row, true) ?></pre>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php else: ?>
    <div style="color:red;">SKU <?= htmlspecialchars($sku) ?> wurde in keiner Tabelle gefunden.</div>
<?php endif; ?>
</body>
</html>
```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\ProductPDO;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$tables = ['object_query_1'];
$pdo = ProductPDO::get();

$materials = [];
$examples = [];

foreach ($tables as $table) {

    // Alle Materialien holen
    $stmt = $pdo->query("
        SELECT DISTINCT customfield_asf_material
        FROM `$table`
        WHERE 
            customfield_asf_model LIKE 'S0%' OR
            customfield_asf_model LIKE 'T0%' OR
            customfield_asf_model LIKE 'E0%' OR
            customfield_asf_model LIKE 'C0%' OR
            customfield_asf_model LIKE 'W0%'
        ORDER BY customfield_asf_material
    ");

    $materials = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Für jedes Material genau einen Beispieldatensatz holen
    foreach ($materials as $material) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM `$table`
            WHERE customfield_asf_material = :material
            LIMIT 1
        ");

        $stmt->execute(['material' => $material]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $examples[$material] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Material Analyse</title>

    <style>
        body {
            font-family: monospace;
            background:#111;
            color:#eee;
            padding:20px;
        }

        pre {
            background:#222;
            padding:15px;
            border-radius:8px;
            overflow-x:auto;
        }

        h1 { color:#0af; }

        .material {
            color:#0f0;
            font-size:18px;
            margin-top:30px;
        }
    </style>

</head>
<body>

<h1>Material Analyse</h1>

<h2>Gefundene Materialien</h2>

<ul>
    <?php foreach ($materials as $material): ?>
        <li><?= htmlspecialchars($material) ?></li>
    <?php endforeach; ?>
</ul>

<h2>Beispieldatensätze</h2>

<?php foreach ($examples as $material => $row): ?>

    <div class="material">
        Material: <?= htmlspecialchars($material) ?>
    </div>

    <pre><?= print_r($row, true) ?></pre>

<?php endforeach; ?>

</body>
</html>
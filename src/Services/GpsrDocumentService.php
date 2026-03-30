<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\ProductPDO;
use FPDF;
use PDO;
use RuntimeException;

final class GpsrDocumentService
{
    private const TEMPLATE_FILE = 'gpsr_jewelry_template.html';

    private PDO $productPdo;

    public function __construct(?PDO $productPdo = null)
    {
        $this->productPdo = $productPdo ?? ProductPDO::get();
    }

    /**
     * Erzeugt HTML-Vorschau + PDF für ein Modell.
     *
     * @return array{model:string,html_template:string,html_output:string,pdf_output:string}
     */
    public function generateForModel(string $model): array
    {
        $model = strtoupper(trim($model));
        if ($model === '') {
            throw new RuntimeException('Modell darf nicht leer sein.');
        }

        $productRow = $this->loadProductRow($model);
        if ($productRow === null) {
            throw new RuntimeException('Produktdatensatz für Modell ' . $model . ' nicht gefunden.');
        }

        $data = $this->buildTemplateData($model, $productRow);
        $html = $this->renderTemplate($data);

        $htmlOutput = $this->getGeneratedHtmlPath($model);
        $pdfOutput = $this->getPdfPath($model);

        $this->ensureDirectories();
        file_put_contents($htmlOutput, $html);
        $this->renderPdf($pdfOutput, $data);

        return [
            'model' => $model,
            'html_template' => $this->getTemplatePath(),
            'html_output' => $htmlOutput,
            'pdf_output' => $pdfOutput,
        ];
    }

    private function loadProductRow(string $model): ?array
    {
        $stmt = $this->productPdo->prepare(
            "SELECT *
             FROM object_query_1
             WHERE customfield_asf_model = :model
             LIMIT 1"
        );

        $stmt->execute(['model' => $model]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $productRow
     * @return array<string, string>
     */
    private function buildTemplateData(string $model, array $productRow): array
    {
        $material = trim((string) ($productRow['customfield_asf_material'] ?? ''));
        if ($material === '') {
            $material = 'Nicht angegeben';
        }

        $reference = trim((string) ($productRow['oo_id'] ?? ''));
        if ($reference === '') {
            $reference = $model;
        }

        $productName = 'VONJACOB Partnerringe Modell ' . $model;
        $usage = 'Schmuckring zur dekorativen Verwendung am Finger';
        $validityScope = 'Dieses Dokument gilt für alle Größenvarianten des Modells ' . $model . '.';

        $gpsrWarnings = [
            'Schmuck ist kein Spielzeug. Nicht geeignet für Kinder unter 3 Jahren (Verschluckungsgefahr bei Kleinteilen).',
            'Bei Hautreizungen oder allergischen Reaktionen sofort ablegen und gegebenenfalls ärztlichen Rat einholen.',
            'Nicht beim Sport, bei handwerklichen Tätigkeiten oder bei Arbeiten mit Maschinen tragen.',
            'Kontakt mit Chemikalien wie Reinigungsmitteln, Chlor, Säuren oder Parfüm vermeiden.',
            'Bei sichtbarer Beschädigung nicht weiterverwenden.',
            'Von offenen Flammen und extremen Hitzequellen fernhalten.',
        ];

        $care = [
            'Mit einem weichen, trockenen Tuch reinigen.',
            'Keine aggressiven Reinigungsmittel verwenden.',
            'Trocken lagern und vor starker Feuchtigkeit schützen.',
        ];

        $reachPoints = [
            'Das Produkt enthält keine besonders besorgniserregenden Stoffe (SVHC) aus der aktuellen Kandidatenliste der Europäischen Chemikalienagentur (ECHA) in einer Konzentration über 0,1 Massen-%.',
            'Falls das Produkt SVHC-Stoffe enthalten sollte, verpflichten wir uns, Amazon und die Kunden gemäß Artikel 33 REACH-Verordnung zu informieren.',
            'Das Produkt entspricht den Anforderungen hinsichtlich Schwermetallen, insbesondere in Bezug auf Blei (Pb), Cadmium (Cd), Quecksilber (Hg), Chrom VI (Cr6+) und Nickel (Ni), gemäß relevanten Normen wie EN 1811, EN 12472 oder EN 62321 (je nach Produktkategorie).',
            'Falls zutreffend, wurden Materialanalysen durch ein unabhängiges, akkreditiertes Labor durchgeführt und können auf Anfrage bereitgestellt werden.',
        ];

        $signatureLine = 'Lich, ' . $this->getCurrentDate() . ', Fehmi Jacob';

        return [
            'document_title' => 'Produktsicherheits- und Warnhinweise gemäß EU-Verordnung 2023/988 (GPSR)',
            'manufacturer_line_1' => 'ASF GmbH / VONJACOB',
            'manufacturer_line_2' => 'Unterstadt 26, 35423 Lich, Deutschland',
            'manufacturer_contact_person' => 'Fehmi Jacob',
            'manufacturer_email' => 'amazon@vonjacob.de',
            'manufacturer_phone' => '06404 803 92 70',
            'manufacturer_website' => 'https://vonjacob.de/',
            'product_name' => $productName,
            'model_reference' => $model,
            'article_reference' => $reference,
            'material' => $material,
            'usage' => $usage,
            'validity_scope' => $validityScope,
            'intended_use' => 'Dieses Produkt ist als Schmuckstück zur dekorativen Verwendung vorgesehen. Es ist nicht für andere Zwecke bestimmt.',
            'gpsr_warnings_html' => $this->buildHtmlListItems($gpsrWarnings),
            'care_html' => $this->buildHtmlListItems($care),
            'reach_points_html' => $this->buildHtmlListItems($reachPoints),
            'gpsr_warnings_text' => implode("\n", $gpsrWarnings),
            'care_text' => implode("\n", $care),
            'reach_points_text' => implode("\n", $reachPoints),
            'reach_title' => 'REACH-Konformitätserklärung gemäß Verordnung (EG) Nr. 1907/2006',
            'reach_company_name' => 'ASF GmbH / VONJACOB',
            'reach_company_address' => 'Unterstadt 26, 35423 Lich',
            'reach_contact_person' => 'Fehmi Jacob',
            'reach_email' => 'amazon@vonjacob.de',
            'reach_phone' => '06404 803 92 70',
            'reach_product_name' => 'Partnerringe',
            'reach_product_full_name' => $productName,
            'reach_article_reference' => $reference,
            'reach_material_description' => $material,
            'reach_declaration_intro' => 'Hiermit bestätigen wir, dass das oben genannte Produkt die Anforderungen der REACH-Verordnung (EG) Nr. 1907/2006 erfüllt.',
            'signature_line' => $signatureLine,
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function renderTemplate(array $data): string
    {
        $templatePath = $this->getTemplatePath();
        if (!is_file($templatePath)) {
            throw new RuntimeException('HTML-Template nicht gefunden: ' . $templatePath);
        }

        $template = (string) file_get_contents($templatePath);
        $replace = [];

        foreach ($data as $key => $value) {
            $replace['{{' . $key . '}}'] = $value;
        }

        return strtr($template, $replace);
    }

    /**
     * @param array<int, string> $items
     */
    private function buildHtmlListItems(array $items): string
    {
        $html = [];

        foreach ($items as $item) {
            $html[] = '<li>' . htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }

        return implode("\n", $html);
    }

    /**
     * @param array<string, string> $data
     */
    private function renderPdf(string $pdfPath, array $data): void
    {
        $this->includeFpdf();

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $this->renderGpsrSection($pdf, $data);
        $this->renderReachSection($pdf, $data);

        $pdf->Output('F', $pdfPath);
    }

    /**
     * @param array<string, string> $data
     */
    private function renderGpsrSection(FPDF $pdf, array $data): void
    {
        $this->writeHeading($pdf, $data['document_title']);
        $this->writeSectionTitle($pdf, 'Hersteller / Verantwortlicher Wirtschaftsakteur');
        $this->writeParagraph($pdf, $data['manufacturer_line_1']);
        $this->writeParagraph($pdf, $data['manufacturer_line_2']);
        $this->writeParagraph($pdf, 'E-Mail: ' . $data['manufacturer_email']);
        $this->writeParagraph($pdf, 'Telefon: ' . $data['manufacturer_phone']);
        $this->writeParagraph($pdf, 'Website: ' . $data['manufacturer_website']);

        $this->writeSectionTitle($pdf, 'Produktinformationen');
        $this->writeLabelValue($pdf, 'Produktname', $data['product_name']);
        $this->writeLabelValue($pdf, 'Modell / Artikelreferenz', $data['model_reference']);
        $this->writeLabelValue($pdf, 'Artikelnummer', $data['article_reference']);
        $this->writeLabelValue($pdf, 'Material', $data['material']);
        $this->writeLabelValue($pdf, 'Verwendung', $data['usage']);
        $this->writeLabelValue($pdf, 'Gültigkeit', $data['validity_scope']);

        $this->writeSectionTitle($pdf, 'Bestimmungsgemäße Verwendung');
        $this->writeParagraph($pdf, $data['intended_use']);

        $this->writeSectionTitle($pdf, 'Sicherheits- und Warnhinweise');
        $this->writeBulletList($pdf, explode("\n", $data['gpsr_warnings_text']));

        $this->writeSectionTitle($pdf, 'Pflegehinweise');
        $this->writeBulletList($pdf, explode("\n", $data['care_text']));

        $this->writeSectionTitle($pdf, 'Ort, Datum, Name, Unterschrift');
        $this->writeParagraph($pdf, $data['signature_line']);
    }

    /**
     * @param array<string, string> $data
     */
    private function renderReachSection(FPDF $pdf, array $data): void
    {
        $pdf->AddPage();

        $this->writeHeading($pdf, $data['reach_title']);
        $this->writeSectionTitle($pdf, 'Angaben zum Unternehmen');
        $this->writeLabelValue($pdf, 'Firma', $data['reach_company_name']);
        $this->writeLabelValue($pdf, 'Adresse', $data['reach_company_address']);
        $this->writeLabelValue($pdf, 'Kontaktperson', $data['reach_contact_person']);
        $this->writeLabelValue($pdf, 'E-Mail', $data['reach_email']);
        $this->writeLabelValue($pdf, 'Telefon', $data['reach_phone']);

        $this->writeSectionTitle($pdf, 'Produktinformationen');
        $this->writeLabelValue($pdf, 'Produktname', $data['reach_product_name']);
        $this->writeLabelValue($pdf, 'Produktbezeichnung', $data['reach_product_full_name']);
        $this->writeLabelValue($pdf, 'Artikelnummer', $data['reach_article_reference']);
        $this->writeLabelValue($pdf, 'Materialbeschreibung', $data['reach_material_description']);

        $this->writeSectionTitle($pdf, 'Erklärung zur REACH-Konformität');
        $this->writeParagraph($pdf, $data['reach_declaration_intro']);
        $this->writeBulletList($pdf, explode("\n", $data['reach_points_text']));

        $this->writeSectionTitle($pdf, 'Ort, Datum, Name, Unterschrift');
        $this->writeParagraph($pdf, $data['signature_line']);
    }

    private function writeHeading(FPDF $pdf, string $text): void
    {
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->MultiCell(0, 7, $this->toPdfText($text));
        $pdf->Ln(2);
    }

    private function writeSectionTitle(FPDF $pdf, string $text): void
    {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 6, $this->toPdfText($text));
        $pdf->SetFont('Arial', '', 10);
    }

    private function writeParagraph(FPDF $pdf, string $text): void
    {
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 5.5, $this->toPdfText($text));
    }

    private function writeLabelValue(FPDF $pdf, string $label, string $value): void
    {
        $this->writeParagraph($pdf, $label . ': ' . $value);
    }

    /**
     * @param array<int, string> $items
     */
    private function writeBulletList(FPDF $pdf, array $items): void
    {
        $pdf->SetFont('Arial', '', 10);

        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $pdf->Cell(4, 5.5, $this->toPdfText('-'));
            $pdf->MultiCell(0, 5.5, $this->toPdfText($item));
        }
    }

    private function includeFpdf(): void
    {
        $fpdfFile = dirname(__DIR__, 3) . '/fpdf/fpdf.php';
        if (!is_file($fpdfFile)) {
            throw new RuntimeException('FPDF wurde nicht gefunden unter: ' . $fpdfFile);
        }

        if (!class_exists('FPDF')) {
            require_once $fpdfFile;
        }
    }

    private function ensureDirectories(): void
    {
        foreach ([
                     $this->getStorageRoot(),
                     $this->getHtmlRoot(),
                     dirname($this->getGeneratedHtmlPath('DUMMY')),
                     $this->getPdfRoot(),
                 ] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $directory);
            }
        }
    }

    private function getCurrentDate(): string
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        return (new \DateTimeImmutable('now', $tz))->format('d.m.Y');
    }

    private function getTemplatePath(): string
    {
        return $this->getHtmlRoot() . '/' . self::TEMPLATE_FILE;
    }

    private function getGeneratedHtmlPath(string $model): string
    {
        return $this->getHtmlRoot() . '/generated/' . strtolower($model) . '.html';
    }

    private function getPdfPath(string $model): string
    {
        return $this->getPdfRoot() . '/' . strtolower($model) . '.pdf';
    }

    private function getStorageRoot(): string
    {
        return dirname(__DIR__, 2) . '/storage';
    }

    private function getHtmlRoot(): string
    {
        return $this->getStorageRoot() . '/html';
    }

    private function getPdfRoot(): string
    {
        return $this->getStorageRoot() . '/pdf';
    }

    private function toPdfText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT', $value);
        return $converted !== false ? $converted : $value;
    }
}

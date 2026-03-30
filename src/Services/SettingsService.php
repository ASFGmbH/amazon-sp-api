<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

final class SettingsService
{
    public const INVENTORY_MINSTOCK_KEY = 'amazon_inventory.minstock';
    public const INVENTORY_FINALIZE_REPORT_ENABLED_KEY = 'amazon_inventory.finalize_report_enabled';
    public const INVENTORY_FINALIZE_REPORT_EMAIL_KEY = 'amazon_inventory.finalize_report_email';

    public function __construct(
        private PDO $pdo
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => $key]);

        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $sql = '
            INSERT INTO settings (setting_key, setting_value)
            VALUES (:key, :value)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'key'   => $key,
            'value' => $value,
        ]);
    }

    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($keys as $index => $key) {
            $ph = ':k' . $index;
            $placeholders[] = $ph;
            $params['k' . $index] = $key;
        }

        $sql = sprintf(
            'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (%s)',
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[(string) $row['setting_key']] = $row['setting_value'] !== null
                ? (string) $row['setting_value']
                : null;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = null;
            }
        }

        return $result;
    }

    public function setMany(array $settings): void
    {
        try {
            $this->pdo->beginTransaction();

            foreach ($settings as $key => $value) {
                $this->set((string) $key, $value !== null ? (string) $value : null);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('Fehler beim Speichern der Einstellungen: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null || trim($value) === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) floor((float) $value);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $normalized = mb_strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'ja', 'on'], true);
    }

    public function getAmazonInventoryMinstock(): int
    {
        return max(0, $this->getInt(self::INVENTORY_MINSTOCK_KEY, 0));
    }

    /**
     * @return array{enabled:bool,email:?string}
     */
    public function getAmazonInventoryFinalizeReportSettings(): array
    {
        $email = $this->get(self::INVENTORY_FINALIZE_REPORT_EMAIL_KEY);
        $email = $email !== null ? trim($email) : null;

        return [
            'enabled' => $this->getBool(self::INVENTORY_FINALIZE_REPORT_ENABLED_KEY, false),
            'email' => $email !== '' ? $email : null,
        ];
    }

    public function getAmazonTemplateSettings(): array
    {
        $keys = [
            'amazon_title_template',
            'amazon_description_template',
            'amazon_bullet_1',
            'amazon_bullet_2',
            'amazon_bullet_3',
            'amazon_bullet_4',
            'amazon_bullet_5',
            'amazon_bullet_6',
            'amazon_country_of_origin',
            'amazon_price.tax_rate',
            'amazon_price.round_to',
            'amazon_price.min_price',
            'amazon_price.default_price_group',
            'amazon_recommended_browse_node',
            'amazon_gem_type_default',
            'amazon_department',
            'amazon_color',
            'amazon_supplier_declared_dg_hz_regulation',
            'amazon_jewelry_material_categorization',
            'amazon_supplier_declared_has_product_identifier_exemption',
            self::INVENTORY_MINSTOCK_KEY,
            self::INVENTORY_FINALIZE_REPORT_ENABLED_KEY,
            self::INVENTORY_FINALIZE_REPORT_EMAIL_KEY,
        ];

        return $this->getMany($keys);
    }
}

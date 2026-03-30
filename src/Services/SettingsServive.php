<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

final class SettingsService
{
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

    public function getAmazonTemplateSettings(): array
    {
        $keys = [
            'amazon_title_template',
            'amazon_bullet_1',
            'amazon_bullet_2',
            'amazon_bullet_3',
            'amazon_bullet_4',
            'amazon_bullet_5',
            'amazon_country_of_origin',
        ];

        $data = $this->getMany($keys);

        return [
            'amazon_title_template'    => $data['amazon_title_template'] ?? '',
            'amazon_bullet_1'          => $data['amazon_bullet_1'] ?? '',
            'amazon_bullet_2'          => $data['amazon_bullet_2'] ?? '',
            'amazon_bullet_3'          => $data['amazon_bullet_3'] ?? '',
            'amazon_bullet_4'          => $data['amazon_bullet_4'] ?? '',
            'amazon_bullet_5'          => $data['amazon_bullet_5'] ?? '',
            'amazon_country_of_origin' => $data['amazon_country_of_origin'] ?? 'DE',
        ];
    }
}
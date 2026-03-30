<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AmazonImageHelper
{
    private const BASIC_IMAGES = [
        'https://toolbox.asf.gmbh/amazon-sp-api/media/basic/4-gravur.jpg',
        'https://toolbox.asf.gmbh/amazon-sp-api/media/basic/5-ringgroesse.png',
        'https://toolbox.asf.gmbh/amazon-sp-api/media/basic/6-etui-versand.png',
        'https://toolbox.asf.gmbh/amazon-sp-api/media/basic/7-vorteile.png',
    ];

    public function __construct(
        private ?MediaScanner $mediaScanner = null
    ) {
        $this->mediaScanner ??= new MediaScanner();
    }

    /**
     * @return array{
     *   main:string,
     *   gallery:array<int,string>,
     *   all:array<int,string>
     * }
     */
    public function getImageUrlsForModel(string $model): array
    {
        $mediaRoot = realpath(__DIR__ . '/../../media');
        if ($mediaRoot === false) {
            throw new RuntimeException('Media-Ordner nicht gefunden.');
        }

        $scan = $this->mediaScanner->scan($mediaRoot);

        $found = null;
        foreach ($scan as $row) {
            if (strtoupper((string) ($row['model'] ?? '')) === strtoupper($model)) {
                $found = $row;
                break;
            }
        }

        if (!is_array($found) || empty($found['main'])) {
            throw new RuntimeException('Kein Hauptbild für Modell ' . $model . ' gefunden.');
        }

        $main = $this->localPathToUrl((string) $found['main']);
        $gallery = [];

        foreach ((array) ($found['gallery'] ?? []) as $img) {
            $gallery[] = $this->localPathToUrl((string) $img);
        }

        $all = array_merge([$main], $gallery, self::BASIC_IMAGES);
        $all = array_values(array_unique(array_filter($all)));

        return [
            'main' => $main,
            'gallery' => $gallery,
            'all' => $all,
        ];
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public function buildImageAttributes(string $model, string $marketplaceId): array
    {
        $images = $this->getImageUrlsForModel($model);
        $all = $images['all'];

        $result = [];

        if (!isset($all[0])) {
            throw new RuntimeException('Es konnte kein Hauptbild für ' . $model . ' ermittelt werden.');
        }

        $result['main_product_image_locator'] = [[
            'media_location' => $all[0],
            'marketplace_id' => $marketplaceId,
        ]];

        $slots = [
            'other_product_image_locator_1',
            'other_product_image_locator_2',
            'other_product_image_locator_3',
            'other_product_image_locator_4',
            'other_product_image_locator_5',
            'other_product_image_locator_6',
            'other_product_image_locator_7',
            'other_product_image_locator_8',
        ];

        $otherImages = array_slice($all, 1, 8);

        foreach ($otherImages as $index => $url) {
            $result[$slots[$index]] = [[
                'media_location' => $url,
                'marketplace_id' => $marketplaceId,
            ]];
        }

        return $result;
    }

    private function localPathToUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $pos = stripos($normalized, '/media/');

        if ($pos === false) {
            throw new RuntimeException('Pfad konnte nicht in Media-URL umgewandelt werden: ' . $path);
        }

        $relative = substr($normalized, $pos);

        return 'https://toolbox.asf.gmbh/amazon-sp-api' . $relative;
    }
}
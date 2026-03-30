<?php

namespace App\Services;

class MediaScanner
{
    public function scan(string $path): array
    {
        $models = [];
        $dirs = glob($path . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            if (basename($dir) === 'basic') continue;

            $images = glob($dir . '/*.jpg');
            $main = null;
            $gallery = [];

            foreach ($images as $img) {
                if (preg_match('/-(b|c|d|e|f)\./', $img)) {
                    $gallery[] = $img;
                } else {
                    $main = $img;
                }
            }

            $models[] = [
                'model' => basename($dir),
                'main' => $main,
                'gallery' => $gallery
            ];
        }

        return $models;
    }
}
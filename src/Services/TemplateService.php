<?php
declare(strict_types=1);

namespace App\Services;

final class TemplateService
{
    public function render(string $template, array $variables = []): string
    {
        $replace = [];

        foreach ($variables as $key => $value) {
            $replace['{{' . $key . '}}'] = (string) $value;
        }

        $result = strtr($template, $replace);

        // Nicht ersetzte Platzhalter entfernen oder stehen lassen?
        // Hier lassen wir sie bewusst stehen, damit man Fehler leichter sieht.
        return trim(preg_replace('/\s+/', ' ', $result) ?? $result);
    }

    public function renderBulletPoints(array $templates, array $variables = []): array
    {
        $result = [];

        foreach ($templates as $template) {
            $template = trim((string) $template);
            if ($template === '') {
                continue;
            }

            $result[] = $this->render($template, $variables);
        }

        return $result;
    }
}
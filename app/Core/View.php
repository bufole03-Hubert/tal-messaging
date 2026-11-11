<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        try {
            $data['csrf'] = Session::csrfToken();
        } catch (ApiException $e) {
            if ($e->errorCode !== 'SETUP_REQUIRED') {
                throw $e;
            }
            $data['csrf'] = ''; // page d'erreur d'installation : pas de session disponible
        }
        $content = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        $data['content'] = $content;
        return self::capture($layout, $data);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $template, array $data): string
    {
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Vue introuvable : ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}

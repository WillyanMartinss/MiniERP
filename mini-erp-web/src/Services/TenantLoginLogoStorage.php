<?php
declare(strict_types=1);

namespace MiniErp\Services;

use InvalidArgumentException;
use RuntimeException;

final class TenantLoginLogoStorage
{
    public function __construct(private string $publicDirectory) {}

    public function normalize(string $bytes): string
    {
        if (strlen($bytes) > 2 * 1024 * 1024) throw new InvalidArgumentException('A logo deve ter no máximo 2 MB.');
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || $info[0] > 2048 || $info[1] > 2048) {
            throw new InvalidArgumentException('Envie uma imagem PNG, JPG ou WebP de até 2048 × 2048 pixels.');
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new InvalidArgumentException('Não foi possível ler a imagem enviada.');
        imagesavealpha($image, true);
        ob_start();
        try {
            if (!imagepng($image)) throw new RuntimeException('Não foi possível preparar a logo.');
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    private function path(int $tenantId): string
    {
        if ($tenantId < 1) throw new InvalidArgumentException('Empresa inválida.');
        return $this->publicDirectory . '/uploads/tenant-logos/' . $tenantId . '.png';
    }

    public function save(int $tenantId, string $bytes): void
    {
        $png = $this->normalize($bytes);
        $path = $this->path($tenantId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta de logos.');
        }
        $temporary = tempnam($directory, 'logo-');
        if ($temporary === false) throw new RuntimeException('Não foi possível salvar a logo.');
        try {
            if (file_put_contents($temporary, $png, LOCK_EX) !== strlen($png) || !rename($temporary, $path)) {
                throw new RuntimeException('Não foi possível salvar a logo.');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    public function url(int $tenantId): ?string
    {
        $path = $this->path($tenantId);
        return is_file($path) ? '/uploads/tenant-logos/' . $tenantId . '.png?v=' . substr((string) sha1_file($path), 0, 12) : null;
    }
}

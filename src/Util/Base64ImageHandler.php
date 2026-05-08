<?php

namespace App\Util;

class Base64ImageHandler
{
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const EXT_MAP = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /**
     * Decodes a base64 image string (with or without data URI prefix) and saves it to disk.
     * Returns the saved filename, or throws \InvalidArgumentException on invalid input.
     */
    public static function save(string $base64, string $directory, string $prefix = 'img'): string
    {
        $mime = 'image/jpeg';
        $data = $base64;

        if (str_starts_with($base64, 'data:')) {
            [$header, $data] = explode(',', $base64, 2);
            preg_match('/data:([^;]+);base64/', $header, $m);
            $mime = $m[1] ?? 'image/jpeg';
        }

        if (!in_array($mime, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported image type: ' . $mime);
        }

        $binary = base64_decode($data, strict: true);
        if ($binary === false) {
            throw new \InvalidArgumentException('Invalid base64 data.');
        }

        $ext      = self::EXT_MAP[$mime] ?? 'jpg';
        $filename = $prefix . '-' . uniqid() . '.' . $ext;

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($directory . '/' . $filename, $binary);

        return $filename;
    }
}

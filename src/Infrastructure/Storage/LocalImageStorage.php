<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use RuntimeException;

final class LocalImageStorage
{
    private const MAX_BYTES = 2_097_152;
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @param array{tmp_name:string,name:string,size:int,type?:string,error:int} $uploadedFile
     */
    public function storeClinicImage(string $clinicId, array $uploadedFile): string
    {
        return $this->store('clinics', $clinicId, $uploadedFile);
    }

    /**
     * @param array{tmp_name:string,name:string,size:int,type?:string,error:int} $uploadedFile
     */
    public function storeUserImage(string $userId, array $uploadedFile): string
    {
        return $this->store('users', $userId, $uploadedFile);
    }

    public function deleteByPublicPath(?string $publicPath): void
    {
        if ($publicPath === null || $publicPath === '') {
            return;
        }

        $relative = ltrim(str_replace('/uploads/', '', $publicPath), '/');
        $full = $this->uploadsRoot() . '/' . $relative;
        if (is_file($full)) {
            unlink($full);
        }
    }

    /**
     * @param array{tmp_name:string,name:string,size:int,type?:string,error:int} $uploadedFile
     */
    private function store(string $folder, string $entityId, array $uploadedFile): string
    {
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Invalid upload');
        }

        if (($uploadedFile['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Image exceeds maximum size');
        }

        $mime = mime_content_type((string) $uploadedFile['tmp_name']) ?: '';
        $extension = self::ALLOWED_MIME[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Unsupported image type');
        }

        $dir = $this->uploadsRoot() . '/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create upload directory');
        }

        $filename = $entityId . '.' . $extension;
        $target = $dir . '/' . $filename;
        if (!move_uploaded_file((string) $uploadedFile['tmp_name'], $target)) {
            throw new RuntimeException('Failed to store image');
        }

        return '/uploads/' . $folder . '/' . $filename;
    }

    private function uploadsRoot(): string
    {
        return rtrim($this->projectRoot, '/\\') . '/storage/uploads';
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AvatarUploadPolicy
{
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'heic',
        'heif',
    ];

    public function __construct(
        #[Autowire('%env(int:APP_MAX_AVATAR_BYTES)%')]
        private readonly int $maxAvatarBytes,
    ) {
    }

    public function validate(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('La imagen de perfil no es válida.');
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > $this->maxAvatarBytes) {
            throw new \RuntimeException(sprintf('La imagen excede el límite permitido de %d bytes.', $this->maxAvatarBytes));
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        if ('' !== $mime) {
            if (!str_starts_with($mime, 'image/')) {
                throw new \RuntimeException('Formato de archivo inválido. Debe ser una imagen.');
            }

            if ('image/svg+xml' === $mime) {
                throw new \RuntimeException('Formato SVG no permitido por seguridad.');
            }

            return;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Formato de avatar no compatible. Usa JPG, PNG, WebP o HEIC.');
        }
    }
}

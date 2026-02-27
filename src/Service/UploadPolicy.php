<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadPolicy
{
    /**
     * @var list<string>
     */
    private array $blockedExtensions = ['php', 'phtml', 'phar', 'sh', 'exe', 'bat', 'cmd', 'com', 'jar'];

    public function __construct(
        #[Autowire('%env(int:APP_MAX_UPLOAD_BYTES)%')]
        private readonly int $maxUploadBytes,
    ) {
    }

    public function validate(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Archivo inválido.');
        }

        $size = $file->getSize();
        if (!is_int($size) || $size <= 0) {
            throw new \RuntimeException('No se pudo determinar el tamaño del archivo.');
        }

        if ($size > $this->maxUploadBytes) {
            throw new \RuntimeException(sprintf('El archivo excede el límite de %d bytes.', $this->maxUploadBytes));
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($extension, $this->blockedExtensions, true)) {
            throw new \RuntimeException('Tipo de archivo no permitido por seguridad.');
        }
    }
}

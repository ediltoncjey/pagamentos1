<?php

declare(strict_types=1);

namespace App\Utils;

use RuntimeException;

final class FileStorage
{
    public function __construct(
        private readonly string $basePath = '',
    ) {
    }

    /**
     * @param array<string, mixed> $file
     * @param list<string> $allowedExtensions
     */
    public function storeUploadedFile(
        array $file,
        string $subDirectory,
        array $allowedExtensions,
        int $maxBytes
    ): string {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || $originalName === '') {
            throw new RuntimeException('Ficheiro invalido.');
        }

        if ($size <= 0) {
            throw new RuntimeException('Ficheiro vazio.');
        }

        if ($size > $maxBytes) {
            throw new RuntimeException('Ficheiro excede o tamanho permitido.');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Extensao de ficheiro nao permitida.');
        }

        $relativeDirectory = $this->normalizeDirectory($subDirectory);
        $absoluteDirectory = $this->storageRoot() . DIRECTORY_SEPARATOR . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !@mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('Falha ao preparar diretorio de upload.');
        }

        $targetName = bin2hex(random_bytes(20)) . '.' . $extension;
        $absoluteTarget = $absoluteDirectory . DIRECTORY_SEPARATOR . $targetName;
        $relativeTarget = str_replace('\\', '/', $relativeDirectory . DIRECTORY_SEPARATOR . $targetName);

        $moved = false;
        if (is_uploaded_file($tmpName)) {
            $moved = move_uploaded_file($tmpName, $absoluteTarget);
        }

        if (!$moved) {
            // Fallback para cenarios de testes locais e execucao CLI.
            $moved = @copy($tmpName, $absoluteTarget);
        }

        if (!$moved) {
            throw new RuntimeException('Nao foi possivel gravar o ficheiro.');
        }

        return $relativeTarget;
    }

    public function delete(string $relativePath): void
    {
        if ($relativePath === '') {
            return;
        }

        $normalized = str_replace(['\\', '..'], ['/', ''], $relativePath);
        $absolute = $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function storageRoot(): string
    {
        if ($this->basePath !== '') {
            return rtrim($this->basePath, '/\\');
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'private';
    }

    private function normalizeDirectory(string $directory): string
    {
        $clean = trim(str_replace('\\', '/', $directory), '/');
        $clean = str_replace('..', '', $clean);
        return $clean === '' ? 'misc' : $clean;
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ficheiro excede o limite permitido.',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto.',
            UPLOAD_ERR_NO_FILE => 'Nenhum ficheiro enviado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretorio temporario indisponivel.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar ficheiro em disco.',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensao do PHP.',
            default => 'Erro desconhecido no upload.',
        };
    }
}

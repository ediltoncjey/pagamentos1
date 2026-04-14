<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\ProductRepository;
use App\Utils\Env;
use App\Utils\FileStorage;
use App\Utils\Logger;
use App\Utils\Sanitizer;
use App\Utils\Validator;
use RuntimeException;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly AuditLogRepository $auditLogs,
        private readonly FileStorage $fileStorage,
        private readonly Sanitizer $sanitizer,
        private readonly Validator $validator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId): array
    {
        return $this->products->listByReseller($resellerId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForReseller(int $productId, int $resellerId): ?array
    {
        return $this->products->findByIdAndReseller($productId, $resellerId);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function createProduct(int $resellerId, array $input, array $files = [], array $context = []): array
    {
        $prepared = $this->preparePayload($input, $files, null, $resellerId);

        $productId = $this->products->create([
            'reseller_id' => $resellerId,
            'name' => $prepared['name'],
            'description' => $prepared['description'],
            'product_type' => $prepared['product_type'],
            'price' => $prepared['price'],
            'currency' => $prepared['currency'],
            'image_path' => $prepared['image_path'],
            'delivery_type' => $prepared['delivery_type'],
            'external_url' => $prepared['external_url'],
            'file_path' => $prepared['file_path'],
            'is_active' => $prepared['is_active'],
        ]);

        $product = $this->products->findByIdAndReseller($productId, $resellerId);
        if ($product === null) {
            throw new RuntimeException('Falha ao criar produto.');
        }

        $this->registerAudit(
            action: 'product.create',
            productId: $productId,
            context: $context,
            newValues: [
                'name' => $product['name'],
                'price' => $product['price'],
                'currency' => $product['currency'],
                'delivery_type' => $product['delivery_type'],
            ]
        );

        return $product;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function updateProduct(
        int $productId,
        int $resellerId,
        array $input,
        array $files = [],
        array $context = []
    ): array {
        $existing = $this->products->findByIdAndReseller($productId, $resellerId);
        if ($existing === null) {
            throw new RuntimeException('Produto nao encontrado.');
        }

        $prepared = $this->preparePayload($input, $files, $existing, $resellerId);
        $oldValues = [
            'name' => $existing['name'],
            'price' => $existing['price'],
            'currency' => $existing['currency'],
            'delivery_type' => $existing['delivery_type'],
            'is_active' => $existing['is_active'],
        ];

        $this->products->updateByIdAndReseller($productId, $resellerId, [
            'name' => $prepared['name'],
            'description' => $prepared['description'],
            'product_type' => $prepared['product_type'],
            'price' => $prepared['price'],
            'currency' => $prepared['currency'],
            'image_path' => $prepared['image_path'],
            'delivery_type' => $prepared['delivery_type'],
            'external_url' => $prepared['external_url'],
            'file_path' => $prepared['file_path'],
            'is_active' => $prepared['is_active'],
        ]);

        $updated = $this->products->findByIdAndReseller($productId, $resellerId);
        if ($updated === null) {
            throw new RuntimeException('Falha ao atualizar produto.');
        }

        $this->registerAudit(
            action: 'product.update',
            productId: $productId,
            context: $context,
            oldValues: $oldValues,
            newValues: [
                'name' => $updated['name'],
                'price' => $updated['price'],
                'currency' => $updated['currency'],
                'delivery_type' => $updated['delivery_type'],
                'is_active' => $updated['is_active'],
            ]
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function toggleStatus(int $productId, int $resellerId, array $context = []): array
    {
        $existing = $this->products->findByIdAndReseller($productId, $resellerId);
        if ($existing === null) {
            throw new RuntimeException('Produto nao encontrado.');
        }

        $this->products->toggleStatus($productId, $resellerId);
        $updated = $this->products->findByIdAndReseller($productId, $resellerId);
        if ($updated === null) {
            throw new RuntimeException('Falha ao alterar estado do produto.');
        }

        $this->registerAudit(
            action: 'product.toggle_status',
            productId: $productId,
            context: $context,
            oldValues: ['is_active' => $existing['is_active']],
            newValues: ['is_active' => $updated['is_active']]
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function preparePayload(array $input, array $files, ?array $existing, int $resellerId): array
    {
        $name = $this->sanitizer->string($input['name'] ?? ($existing['name'] ?? ''), 180);
        $description = $this->sanitizer->string($input['description'] ?? ($existing['description'] ?? ''), 5000);
        $currency = strtoupper($this->sanitizer->string($input['currency'] ?? ($existing['currency'] ?? 'MZN'), 3));
        $deliveryType = $this->sanitizer->string(
            $input['delivery_type'] ?? ($existing['delivery_type'] ?? 'external_link'),
            20
        );
        $productType = strtolower($this->sanitizer->string(
            $input['product_type'] ?? ($existing['product_type'] ?? 'digital'),
            20
        ));
        $price = round((float) ($input['price'] ?? ($existing['price'] ?? 0)), 2);
        $isActiveInput = $input['is_active'] ?? ($existing['is_active'] ?? 1);
        $isActive = filter_var($isActiveInput, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($isActive === null) {
            $isActive = (int) $isActiveInput === 1 || $isActiveInput === '1';
        }

        $validation = $this->validator->validate(
            [
                'name' => $name,
                'price' => $price,
                'currency' => $currency,
                'product_type' => $productType,
                'delivery_type' => $deliveryType,
            ],
            [
                'name' => 'required|min:3|max:180',
                'price' => 'required|numeric|min:0.01',
                'currency' => 'required|in:MZN',
                'product_type' => 'required|in:digital,physical',
                'delivery_type' => 'required|in:external_link,file_upload,none',
            ]
        );

        if (!$validation['valid']) {
            throw new RuntimeException('Dados invalidos: ' . json_encode($validation['errors']));
        }

        $imagePath = (string) ($existing['image_path'] ?? '');
        $imageFile = $this->extractFile($files, 'image');
        if ($imageFile !== null) {
            $newImagePath = $this->fileStorage->storeUploadedFile(
                file: $imageFile,
                subDirectory: 'products/' . $resellerId . '/images',
                allowedExtensions: $this->imageExtensions(),
                maxBytes: $this->imageMaxBytes()
            );

            if ($imagePath !== '') {
                $this->fileStorage->delete($imagePath);
            }

            $imagePath = $newImagePath;
        }

        $externalUrl = null;
        $filePath = null;
        if ($productType === 'physical') {
            $deliveryType = 'none';

            $existingFile = (string) ($existing['file_path'] ?? '');
            if ($existingFile !== '') {
                $this->fileStorage->delete($existingFile);
            }
            $externalUrl = null;
            $filePath = null;
        } elseif ($deliveryType === 'external_link') {
            $providedUrl = trim((string) ($input['external_url'] ?? ($existing['external_url'] ?? '')));
            if ($providedUrl === '' || !$this->isValidExternalUrl($providedUrl)) {
                throw new RuntimeException('Link externo invalido para entrega do produto.');
            }

            $externalUrl = $providedUrl;
            $filePath = null;

            $existingFile = (string) ($existing['file_path'] ?? '');
            if ($existingFile !== '') {
                $this->fileStorage->delete($existingFile);
            }
        } else {
            $filePath = (string) ($existing['file_path'] ?? '');
            $digitalFile = $this->extractFile($files, 'digital_file');
            if ($digitalFile !== null) {
                $newFilePath = $this->fileStorage->storeUploadedFile(
                    file: $digitalFile,
                    subDirectory: 'products/' . $resellerId . '/files',
                    allowedExtensions: $this->digitalExtensions(),
                    maxBytes: $this->digitalMaxBytes()
                );

                if ($filePath !== '') {
                    $this->fileStorage->delete($filePath);
                }

                $filePath = $newFilePath;
            }

            if ($filePath === '') {
                throw new RuntimeException('Ficheiro do produto e obrigatorio para entrega interna.');
            }

            $externalUrl = null;
        }

        return [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'product_type' => $productType,
            'price' => $price,
            'currency' => $currency,
            'delivery_type' => $deliveryType,
            'external_url' => $externalUrl,
            'file_path' => $filePath !== '' ? $filePath : null,
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'is_active' => $isActive ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    private function registerAudit(
        string $action,
        int $productId,
        array $context,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        try {
            $this->auditLogs->create([
                'actor_user_id' => $context['actor_user_id'] ?? null,
                'actor_role' => $context['actor_role'] ?? 'reseller',
                'action' => $action,
                'entity_type' => 'product',
                'entity_id' => $productId,
                'old_values' => $oldValues !== null
                    ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'new_values' => $newValues !== null
                    ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'request_id' => $context['request_id'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to persist product audit log', [
                'action' => $action,
                'product_id' => $productId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>|null
     */
    private function extractFile(array $files, string $key): ?array
    {
        $file = $files[$key] ?? null;
        if (!is_array($file)) {
            return null;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    private function isValidExternalUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['https', 'http'], true);
    }

    /**
     * @return list<string>
     */
    private function imageExtensions(): array
    {
        $raw = (string) Env::get('PRODUCT_IMAGE_EXTENSIONS', 'jpg,jpeg,png,webp');
        return $this->parseExtensionList($raw);
    }

    /**
     * @return list<string>
     */
    private function digitalExtensions(): array
    {
        $raw = (string) Env::get(
            'PRODUCT_DIGITAL_EXTENSIONS',
            'pdf,zip,rar,7z,doc,docx,ppt,pptx,xls,xlsx,txt,mp4,mp3,epub'
        );
        return $this->parseExtensionList($raw);
    }

    private function imageMaxBytes(): int
    {
        return (int) Env::get('PRODUCT_IMAGE_MAX_BYTES', 5 * 1024 * 1024);
    }

    private function digitalMaxBytes(): int
    {
        return (int) Env::get('PRODUCT_DIGITAL_MAX_BYTES', 100 * 1024 * 1024);
    }

    /**
     * @return list<string>
     */
    private function parseExtensionList(string $csv): array
    {
        $parts = array_map(
            static fn (string $item): string => strtolower(trim($item)),
            explode(',', $csv)
        );

        $filtered = array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
        return $filtered !== [] ? $filtered : ['pdf'];
    }
}

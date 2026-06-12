<?php

namespace Modules\Notification\Rendering;

use App\Foundation\Files\FileStorageService;

/**
 * Stores a rendered PDF per FOUNDATION_FILE_STORAGE (R-NOT-01-D-5). Uses the foundation
 * file service (swappable local/MinIO/S3 disk); the returned storage key is written back
 * to the source entity's metadata.pdf_url by the caller. Key pattern:
 * {bucket}/{year}/{month}/{document_id}.pdf.
 */
class PdfStorageService
{
    public function __construct(private readonly FileStorageService $files) {}

    /** @return array{key:string, fileId:string} */
    public function store(string $operator, string $bucket, string $documentId, string $bytes): array
    {
        $key = sprintf('%s/%s/%s.pdf', $bucket, now()->format('Y/m'), $documentId);
        $file = $this->files->storeContents($bytes, [
            'key' => $key,
            'category' => $bucket,
            'mime_type' => 'application/pdf',
            'filename' => $documentId.'.pdf',
            'owner_type' => 'notification',
            'owner_id' => $documentId,
        ]);

        return ['key' => $key, 'fileId' => $file->file_id];
    }
}

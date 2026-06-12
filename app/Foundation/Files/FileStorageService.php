<?php

namespace App\Foundation\Files;

use App\Foundation\Support\Id;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * FOUNDATION_FILE_STORAGE service. Stores an uploaded file on the configured disk
 * and registers it, or attaches raw content; returns the FileObject. Swappable
 * disk (local/s3) via Laravel's filesystem config — modules never touch storage
 * mechanics directly.
 */
class FileStorageService
{
    public function __construct(private readonly ?string $disk = null) {}

    private function disk(): string
    {
        return $this->disk ?? config('sophix.file_disk', config('filesystems.default', 'local'));
    }

    /** @param array<string,mixed> $meta owner_type, owner_id, category, uploaded_by */
    public function store(UploadedFile $file, array $meta = []): FileObject
    {
        $disk = $this->disk();
        $path = $file->store('sophix/'.($meta['category'] ?? 'misc'), $disk);

        return FileObject::query()->create([
            'file_id' => Id::make('file'),
            'owner_type' => $meta['owner_type'] ?? null,
            'owner_id' => $meta['owner_id'] ?? null,
            'category' => $meta['category'] ?? null,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $meta['uploaded_by'] ?? null,
        ]);
    }

    /**
     * Store raw, in-memory bytes (e.g. a rendered PDF artifact) and register them as a
     * FileObject. Used by NOT-01 artifact rendering, which produces PDF byte streams that
     * never existed as an UploadedFile. The key pattern (e.g. invoices/2026/05/inv_..pdf)
     * is supplied by the caller per FOUNDATION_FILE_STORAGE conventions.
     *
     * @param array<string,mixed> $meta owner_type, owner_id, category, uploaded_by, mime_type, filename, key
     */
    public function storeContents(string $contents, array $meta = []): FileObject
    {
        $disk = $this->disk();
        $key = $meta['key'] ?? 'sophix/'.($meta['category'] ?? 'misc').'/'.Id::make('art').'.bin';
        Storage::disk($disk)->put($key, $contents);

        return FileObject::query()->create([
            'file_id' => Id::make('file'),
            'owner_type' => $meta['owner_type'] ?? null,
            'owner_id' => $meta['owner_id'] ?? null,
            'category' => $meta['category'] ?? null,
            'filename' => $meta['filename'] ?? basename($key),
            'mime_type' => $meta['mime_type'] ?? 'application/octet-stream',
            'size_bytes' => strlen($contents),
            'disk' => $disk,
            'path' => $key,
            'checksum' => hash('sha256', $contents),
            'uploaded_by' => $meta['uploaded_by'] ?? null,
        ]);
    }

    public function contents(FileObject $file): ?string
    {
        return Storage::disk($file->disk)->get($file->path);
    }
}

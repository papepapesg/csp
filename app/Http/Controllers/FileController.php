<?php

namespace App\Http\Controllers;

use App\Foundation\Files\FileObject;
use App\Foundation\Files\FileStorageService;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FOUNDATION_FILE_STORAGE upload/metadata/download API. */
class FileController extends ApiController
{
    public function __construct(private readonly FileStorageService $files) {}

    /** POST /api/files (multipart) */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'owner_type' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
        ]);

        $object = $this->files->store($request->file('file'), [
            'owner_type' => $request->input('owner_type'),
            'owner_id' => $request->input('owner_id'),
            'category' => $request->input('category'),
            'uploaded_by' => $request->user()?->uid,
        ]);

        return ApiResponse::created($object);
    }

    /** GET /api/files/{file} (metadata) */
    public function show(FileObject $file): JsonResponse
    {
        return ApiResponse::item($file);
    }

    /** GET /api/files/{file}/download */
    public function download(FileObject $file): Response
    {
        return new Response($this->files->contents($file) ?? '', 200, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
        ]);
    }
}

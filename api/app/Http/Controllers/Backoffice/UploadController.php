<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    public function __construct(private TenantManager $tenantManager)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $tenantId = $this->tenantManager->id();

        if (!$tenantId) {
            throw ValidationException::withMessages([
                'tenant' => ['Unable to resolve tenant context.'],
            ]);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
        $directory = "products/{$tenantId}";
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = Str::uuid().($extension ? ".{$extension}" : '');

        $path = $file->storeAs($directory, $filename, [
            'disk' => $disk,
        ]);

        if ($disk === 's3' && $path) {
            Storage::disk($disk)->setVisibility($path, 'public');
        }

        if (!$path) {
            throw ValidationException::withMessages([
                'file' => ['Failed to store the uploaded file.'],
            ]);
        }

        $url = Storage::disk($disk)->url($path);

        return response()->json([
            'url' => $url,
        ]);
    }
}


<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileImageController extends Controller
{
    private const STUDENT_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function student(string $filename): BinaryFileResponse
    {
        abort_unless(
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $filename) === 1,
            404
        );

        $relativePath = 'student-profiles/'.$filename;
        $disk = Storage::disk('public');

        abort_unless($disk->exists($relativePath), 404);

        $directory = realpath($disk->path('student-profiles'));
        $imagePath = realpath($disk->path($relativePath));

        abort_unless(
            $directory !== false
                && $imagePath !== false
                && str_starts_with($imagePath, $directory.DIRECTORY_SEPARATOR),
            404
        );

        $mimeType = File::mimeType($imagePath);

        abort_unless(in_array($mimeType, self::STUDENT_IMAGE_MIMES, true), 404);

        return response()->file($imagePath, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

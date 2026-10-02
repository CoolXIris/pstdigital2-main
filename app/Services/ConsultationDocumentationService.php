<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ConsultationDocumentationService
{
    public function store(UploadedFile $file, int $meetingId): string
    {
        $mime = $file->getMimeType();
        $basePath = 'consultation-documentation/'.$meetingId;

        if (is_string($mime) && str_starts_with($mime, 'image/')) {
            return $this->storeOptimizedImage($file, $basePath);
        }

        return $file->store($basePath, 'local');
    }

    private function storeOptimizedImage(UploadedFile $file, string $basePath): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Optimasi gambar tidak tersedia pada server ini. Unggah tautan dokumentasi sebagai alternatif.');
        }

        $dimensions = getimagesize($file->getRealPath());
        if (!$dimensions || $dimensions[0] * $dimensions[1] > 20000000) {
            throw new RuntimeException('Resolusi gambar terlalu besar. Batas pemrosesan adalah 20 megapiksel.');
        }

        $source = imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 1920 / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $optimized = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($optimized, 255, 255, 255);
        imagefill($optimized, 0, 0, $white);
        imagecopyresampled($optimized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($optimized, null, 78);
        $contents = ob_get_clean();
        imagedestroy($source);
        imagedestroy($optimized);

        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('Gambar gagal dioptimalkan.');
        }

        $path = $basePath.'/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $contents);

        return $path;
    }
}

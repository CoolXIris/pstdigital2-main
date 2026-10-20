<?php

namespace App\Services;

use App\Models\User;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ConsultationDocumentationService
{
    public function store(UploadedFile $file, int $meetingId, User $uploader): string
    {
        $mime = (string) $file->getMimeType();
        $name = $this->driveFileName($file, $meetingId);

        if (str_starts_with($mime, 'image/')) {
            $contents = $this->optimizedImageContents($file);
            $mime = 'image/jpeg';
            $name = pathinfo($name, PATHINFO_FILENAME).'.jpg';
        } else {
            $contents = $file->get();
        }

        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('Berkas dokumentasi tidak dapat dibaca.');
        }

        try {
            $drive = $this->driveApi($uploader);
            $uploadedFile = $drive->files->create(new DriveFile([
                'name' => $name,
                'mimeType' => $mime,
                'description' => 'Dokumentasi konsultasi PST Digital #'.$meetingId,
            ]), [
                'data' => $contents,
                'mimeType' => $mime,
                'uploadType' => 'multipart',
                'fields' => 'id,webViewLink',
            ]);

            $fileId = $uploadedFile->getId();
            if (!$fileId) {
                throw new RuntimeException('Google Drive tidak mengembalikan ID berkas.');
            }

            $this->shareWithApplicationAdministrators($drive, $fileId, $uploader);

            return 'gdrive:'.$fileId;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            if ((int) $exception->getCode() === 403) {
                throw new RuntimeException('Google Drive menolak unggahan. Pastikan Google Drive API aktif dan akun sudah memberi izin Drive kepada aplikasi.');
            }

            throw new RuntimeException('Dokumentasi gagal diunggah ke Google Drive. Periksa koneksi dan izin akun Google, lalu coba kembali.');
        }
    }

    public function delete(string $storedPath, User $uploader): void
    {
        if (!str_starts_with($storedPath, 'gdrive:')) {
            return;
        }

        $fileId = substr($storedPath, strlen('gdrive:'));
        if ($fileId === '') {
            return;
        }

        try {
            $this->driveApi($uploader)->files->delete($fileId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function driveApi(User $user): Drive
    {
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        if (!$clientId || !$clientSecret) {
            throw new RuntimeException('Konfigurasi Google OAuth belum tersedia di server.');
        }
        if (!filled($user->google_access_token) || !filled($user->google_refresh_token)) {
            throw new RuntimeException('Akun Google belum terhubung untuk unggah Drive. Keluar lalu masuk kembali dan setujui izin Google Drive.');
        }

        $refresh = Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $user->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $accessToken = $refresh->successful() ? $refresh->json('access_token') : null;
        if (!is_string($accessToken) || $accessToken === '') {
            Log::warning('Google OAuth token refresh failed for consultation documentation.', [
                'user_id' => $user->id,
                'http_status' => $refresh->status(),
            ]);
            throw new RuntimeException('Akses Google Drive tidak dapat diperbarui. Hubungkan ulang akun Google admin.');
        }

        $user->google_access_token = $accessToken;
        $user->save();

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setAccessToken($accessToken);

        return new Drive($client);
    }

    private function shareWithApplicationAdministrators(Drive $drive, string $fileId, User $uploader): void
    {
        $administrators = User::role(['admin', 'super_admin'])
            ->whereNotNull('email')
            ->get(['id', 'email']);

        foreach ($administrators as $administrator) {
            if (strcasecmp((string) $administrator->email, (string) $uploader->email) === 0) {
                continue;
            }

            try {
                $drive->permissions->create($fileId, new Permission([
                    'type' => 'user',
                    'role' => 'reader',
                    'emailAddress' => $administrator->email,
                ]), [
                    'sendNotificationEmail' => false,
                    'fields' => 'id',
                ]);
            } catch (Throwable $exception) {
                Log::warning('Unable to share a consultation Drive document with an application administrator.', [
                    'file_id' => $fileId,
                    'administrator_user_id' => $administrator->id,
                    'http_status' => (int) $exception->getCode() ?: null,
                ]);
            }
        }
    }

    private function driveFileName(UploadedFile $file, int $meetingId): string
    {
        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $extension = Str::lower($file->getClientOriginalExtension());

        return 'konsultasi-'.$meetingId.'-'.($baseName ?: 'dokumentasi').'-'.Str::lower(Str::random(8)).($extension ? '.'.$extension : '');
    }

    private function optimizedImageContents(UploadedFile $file): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Optimasi gambar tidak tersedia pada server ini. Unggah gambar berukuran lebih kecil.');
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

        return $contents;
    }
}

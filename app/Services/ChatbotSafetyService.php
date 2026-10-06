<?php

namespace App\Services;

use Illuminate\Support\Str;

class ChatbotSafetyService
{
    /**
     * @return array{category: string, reply: string}|null
     */
    public function check(string $message): ?array
    {
        $normalized = $this->normalize($message);

        foreach ($this->rules() as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized) === 1) {
                    return [
                        'category' => $category,
                        'reply' => $this->templateFor($category),
                    ];
                }
            }
        }

        return null;
    }

    private function normalize(string $message): string
    {
        $message = Str::lower($message);
        $message = preg_replace('/[^\pL\pN]+/u', ' ', $message) ?? $message;
        $message = preg_replace('/(.)\1{2,}/u', '$1$1', $message) ?? $message;

        return trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'abusive' => [
                '/\b(anjing|bangsat|bajingan|kontol|memek|ngentot|goblok|tolol|idiot|bodoh|kampret|sialan|keparat)\b/u',
                '/\b(kamu|anda|lu|lo|kau)\s+(bodoh|goblok|tolol|idiot)\b/u',
            ],
            'adult' => [
                '/\b(porno|pornografi|bokep|bugil|telanjang|nude|hentai|masturbasi|onani|open\s*bo|prostitusi)\b/u',
                '/\b(konten|cerita|gambar|video)\s+(dewasa|seksual|mesum|erotis)\b/u',
            ],
            'violence' => [
                '/\b(bunuh|membunuh|habisi|hajar|aniaya|menyakiti|melukai)\s+(orang|seseorang|dia|mereka|diri|saya)\b/u',
                '/\b(cara|tutorial|panduan|bikin|membuat)\s+(bom|senjata|racun)\b/u',
            ],
            'illegal' => [
                '/\b(cara|tutorial|panduan)\s+(meretas|hack|mencuri|menipu|phishing|carding)\b/u',
                '/\b(jual|beli|edarkan|mengedar)\s+(narkoba|sabu|ganja|ekstasi)\b/u',
            ],
            'privacy' => [
                '/\b(nik|nomor\s+ktp|password|kata\s+sandi|token|otp|api\s*key)\s+(orang|pengguna|user|admin|milik)\b/u',
                '/\b(bocorkan|ambilkan|tampilkan)\s+(data\s+pribadi|password|otp|token|api\s*key)\b/u',
            ],
        ];
    }

    private function templateFor(string $category): string
    {
        return match ($category) {
            'abusive' => 'Maaf, saya tidak dapat memproses pesan yang mengandung bahasa kasar atau menyerang. Silakan ajukan pertanyaan dengan bahasa yang sopan agar saya bisa membantu layanan statistik Anda.',
            'adult' => 'Maaf, saya tidak dapat membantu permintaan yang mengandung konten dewasa atau NSFW. Saya siap membantu pertanyaan seputar data, publikasi, tabel, indikator, dan layanan statistik BPS.',
            'violence' => 'Maaf, saya tidak dapat membantu permintaan yang mengarah pada kekerasan atau tindakan membahayakan. Jika membutuhkan informasi statistik, silakan tuliskan pertanyaan dengan aman dan spesifik.',
            'illegal' => 'Maaf, saya tidak dapat membantu permintaan yang berkaitan dengan aktivitas ilegal. Saya dapat membantu mencari informasi resmi, data statistik, publikasi, atau layanan BPS.',
            'privacy' => 'Maaf, saya tidak dapat membantu membuka atau membagikan data pribadi maupun kredensial. Silakan gunakan kanal resmi dan prosedur yang berwenang untuk kebutuhan administrasi.',
            default => 'Maaf, saya tidak dapat memproses pesan tersebut. Silakan ajukan pertanyaan lain yang sesuai dengan layanan statistik BPS.',
        };
    }
}

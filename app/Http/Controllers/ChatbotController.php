<?php

namespace App\Http\Controllers;

use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledgeSource;
use App\Services\BpsWebApiService;
use App\Services\ChatbotKnowledgeService;
use App\Services\ChatbotSafetyService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatbotController extends Controller
{
    private bool $lastAiReplyUnverified = false;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();
        if ($user->hasRole(['admin', 'super_admin'])) {
            return redirect()->route('admin.chatbot');
        }

        $conversations = ChatbotConversation::where('user_id', $user->id)
            ->withCount('messages')
            ->latest('updated_at')
            ->get(['id', 'title', 'updated_at']);

        return view('chatbot.index', compact('user', 'conversations'));
    }

    public function management(BpsWebApiService $bps): View
    {
        $sources = ChatbotKnowledgeSource::with('uploader:id,name')->latest()->paginate(10);
        $catalogPath = (string) config('services.bps.catalog_csv', base_path('tabel_bps_sumsel.csv'));
        $catalogUpdatedAt = is_file($catalogPath)
            ? \Illuminate\Support\Carbon::createFromTimestamp(filemtime($catalogPath))
            : null;

        return view('admin.chatbot.index', [
            'sources' => $sources,
            'sourceCount' => ChatbotKnowledgeSource::count(),
            'chunkCount' => \App\Models\ChatbotKnowledgeChunk::count(),
            'lastTrainedAt' => ChatbotKnowledgeSource::max('trained_at'),
            'bpsApiKeyConfigured' => $bps->hasApiKey(),
            'catalogUpdatedAt' => $catalogUpdatedAt,
        ]);
    }

    public function refreshBpsCatalog(BpsWebApiService $bps): RedirectResponse
    {
        if (! $bps->hasApiKey()) {
            return back()->with('error', 'API key WebAPI BPS belum dikonfigurasi. Katalog tidak diperbarui.');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(210);
        }

        $lock = Cache::lock('bps:catalog:refresh', 300);
        if (! $lock->get()) {
            return back()->with('error', 'Pembaruan katalog sedang berlangsung. Silakan tunggu hingga selesai.');
        }

        $processSucceeded = false;
        try {
            $catalogPath = (string) config('services.bps.catalog_csv', base_path('tabel_bps_sumsel.csv'));
            $result = Process::path(base_path())
                ->timeout(180)
                ->env(['BPS_API_KEY' => (string) config('services.bps.api_key')])
                ->run([
                    (string) config('services.bps.python_binary', 'python'),
                    base_path('update_bps.py'),
                    '--csv',
                    $catalogPath,
                ]);

            if (! $result->successful()) {
                Log::warning('bps.catalog_refresh_failed', ['exit_code' => $result->exitCode()]);

                return back()->with(
                    'error',
                    'WebAPI BPS gagal memperbarui katalog. Data katalog yang tersimpan tetap dipertahankan; silakan coba lagi.'
                );
            }

            $processSucceeded = true;
            $sync = json_decode(trim($result->output()), true);
            if (! is_array($sync) || ($sync['status'] ?? null) !== 'ok') {
                Log::warning('bps.catalog_refresh_invalid_result');

                return back()->with(
                    'error',
                    'WebAPI BPS merespons, tetapi ringkasan sinkronisasi katalog tidak dapat dibaca. CSV tidak ditampilkan sebagai berhasil diperbarui.'
                );
            }
            foreach (['added_tables', 'added_rows', 'added_periods', 'updated_rows', 'skipped_tables'] as $field) {
                if (! is_int($sync[$field] ?? null) || $sync[$field] < 0) {
                    Log::warning('bps.catalog_refresh_invalid_summary', ['field' => $field]);

                    return back()->with(
                        'error',
                        'Ringkasan sinkronisasi katalog tidak lengkap. Periksa CSV sebelum menjalankan pembaruan ulang.'
                    );
                }
            }

            $bps->invalidateCatalogCaches();
            $summary = $bps->refreshVariableIndex();
            $updatedRows = (int) ($sync['updated_rows'] ?? 0);
            $addedTables = (int) ($sync['added_tables'] ?? 0);
            $addedRows = (int) ($sync['added_rows'] ?? 0);
            $addedPeriods = (int) ($sync['added_periods'] ?? 0);
            $skippedTables = (int) ($sync['skipped_tables'] ?? 0);
            if ($updatedRows === 0 && $addedTables === 0 && $addedRows === 0 && $skippedTables === 0) {
                $message = 'Katalog BPS sudah terbaru. Tidak ada judul, topik, atau periode yang perlu ditambah atau diperbarui.';
            } elseif ($updatedRows === 0 && $addedTables === 0 && $addedRows === 0) {
                $message = "Sinkronisasi belum lengkap: tidak ada perubahan yang diterapkan dan {$skippedTables} entri katalog tidak dapat dipetakan. "
                    .'CSV lama tetap dipertahankan; periksa format respons WebAPI sebelum mencoba lagi.';
            } else {
                $message = "Sinkronisasi katalog BPS selesai. Ada pembaruan: {$addedTables} tabel baru, "
                    ."{$addedPeriods} periode/tahun baru, {$updatedRows} baris judul, topik, atau tautan diperbarui.";
                if ($addedRows > $addedPeriods) {
                    $message .= ' Termasuk '.$addedRows.' baris katalog baru.';
                }
                if ($skippedTables > 0) {
                    $message .= " {$skippedTables} entri tidak dapat dipetakan dan perlu ditinjau.";
                }
            }
            $message .= ' Indeks pencarian diperbarui untuk '.$summary['count'].' tabel dinamis.';

            return back()->with('message', $message);
        } catch (\Throwable $exception) {
            Log::error('bps.catalog_refresh_error', ['exception_class' => $exception::class]);

            return back()->with(
                'error',
                $processSucceeded
                    ? 'CSV katalog telah disinkronkan, tetapi indeks pencarian chatbot gagal diperbarui. Periksa log aplikasi sebelum mencoba ulang.'
                    : 'Pembaruan katalog tidak berhasil dijalankan. Periksa konfigurasi layanan Python dan WebAPI BPS.'
            );
        } finally {
            $lock->release();
        }
    }

    public function uploadKnowledge(Request $request, ChatbotKnowledgeService $knowledge): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'file' => ['required', 'file', 'mimes:txt,md,csv,xlsx', 'max:20480'],
        ]);

        try {
            $source = $knowledge->ingest($validated['file'], $validated['title'], Auth::user());

            return back()->with('message', "Pengetahuan '{$source->title}' berhasil diindeks dan siap digunakan chatbot.");
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function deleteKnowledge(ChatbotKnowledgeSource $source, ChatbotKnowledgeService $knowledge): RedirectResponse
    {
        $knowledge->deleteSource($source);

        return back()->with('message', "Sumber '{$source->title}' dan indeksnya berhasil dihapus.");
    }

    public function testMessage(
        Request $request,
        ChatbotKnowledgeService $knowledge,
        BpsWebApiService $bps,
        ChatbotSafetyService $safety
    ): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return $this->sendMessageToService($validated['message'], (string) Str::uuid(), $knowledge, $bps, $safety);
    }

    public function sendMessage(
        Request $request,
        ChatbotKnowledgeService $knowledge,
        BpsWebApiService $bps,
        ChatbotSafetyService $safety
    ): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $user = Auth::user();
        $conversation = isset($validated['conversation_id'])
            ? ChatbotConversation::where('user_id', $user->id)->findOrFail($validated['conversation_id'])
            : null;
        $sessionKey = $conversation?->session_key ?? (string) Str::uuid();
        $context = null;
        $aiReply = null;
        $safetyResult = $safety->check($validated['message']);

        if ($safetyResult === null) {
            try {
                $context = $this->chatContextFor($knowledge, $bps, $validated['message']);
                $history = $this->conversationHistory($conversation);
                $aiReply = $this->askAi($knowledge, $validated['message'], $sessionKey, $context, $history);
            } catch (\Throwable $exception) {
                report($exception);
                $aiReply = null;
            }
        }

        if ($safetyResult !== null) {
            $reply = $safetyResult['reply'];
        } elseif ($aiReply !== null) {
            $reply = $aiReply;
        } else {
            $reply = $this->fallbackAnswer(
                $bps,
                $validated['message'],
                $context,
                $this->lastAiReplyUnverified,
                $history
            );
        }
        $reply = $this->appendTrustedSources(
            $reply,
            $context,
            $bps,
            $validated['message']
        );
        $knowledgeUsed = $context !== null
            && ($aiReply !== null || ($safetyResult === null && $this->isConceptQuestion($validated['message'])));
        $title = $conversation?->title ?? Str::limit(trim($validated['message']), 180, '...');

        try {
            $conversation ??= ChatbotConversation::create([
                'user_id' => $user->id,
                'session_key' => $sessionKey,
                'title' => $title,
            ]);
            $conversation->messages()->create([
                'prompt' => $validated['message'],
                'response' => $reply,
                'knowledge_used' => $knowledgeUsed,
            ]);
            $conversation->touch();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'reply' => $reply,
            'knowledge_used' => $knowledgeUsed,
            'safety_blocked' => $safetyResult !== null,
            'conversation_id' => $conversation?->id,
            'title' => $conversation?->title ?? $title,
        ]);
    }

    public function conversation(ChatbotConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === Auth::id(), 404);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $conversation->messages()->oldest()->get(['prompt', 'response', 'knowledge_used', 'created_at']),
        ]);
    }

    public function destroyConversation(ChatbotConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === Auth::id(), 404);

        $conversation->messages()->delete();
        $conversation->delete();

        return response()->json(['message' => 'Riwayat percakapan berhasil dihapus.']);
    }

    private function sendMessageToService(
        string $message,
        string $sessionId,
        ChatbotKnowledgeService $knowledge,
        BpsWebApiService $bps,
        ChatbotSafetyService $safety
    ): JsonResponse
    {
        $safetyResult = $safety->check($message);
        if ($safetyResult !== null) {
            return response()->json([
                'reply' => $safetyResult['reply'],
                'knowledge_used' => false,
                'safety_blocked' => true,
            ]);
        }

        try {
            $context = $this->chatContextFor($knowledge, $bps, $message);
            $aiReply = $this->askAi($knowledge, $message, $sessionId, $context);
            $reply = $aiReply ?? $this->fallbackAnswer(
                $bps,
                $message,
                $context,
                $this->lastAiReplyUnverified
            );
            $reply = $this->appendTrustedSources($reply, $context, $bps, $message);

            return response()->json([
                'reply' => $reply,
                'knowledge_used' => $context !== null
                    && ($aiReply !== null || $this->isConceptQuestion($message)),
                'safety_blocked' => false,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Tidak dapat menghubungi layanan chatbot.'], 502);
        }
    }

    private function askAi(ChatbotKnowledgeService $knowledge, string $message, string $sessionId, ?string $context, array $history = []): ?string
    {
        $this->lastAiReplyUnverified = false;
        if ($this->isExplanatoryQuestion($message)) {
            $context = trim(implode("\n\n", array_filter([
                $context,
                'Arahan untuk jawaban: Pengguna meminta penjelasan atau kemungkinan penyebab, bukan pencarian angka baru. '
                    .'Jelaskan faktor umum secara informatif dan hati-hati; jangan menyatakan bahwa faktor tersebut '
                    .'terbukti menyebabkan angka tertentu tanpa bukti. Jika pengguna sudah memberi angka, gunakan '
                    .'angka itu sebagai konteks pertanyaan dan bedakan fakta dari kemungkinan penjelasan.',
            ])));
        } elseif ($this->isConceptQuestion($message)) {
            $context = trim(implode("\n\n", array_filter([
                $context,
                'Arahan untuk jawaban: Pengguna meminta definisi atau penjelasan umum. Jawab langsung dengan bahasa '
                    .'sederhana; jangan mengganti penjelasan dengan tabel atau angka statistik. Gunakan data BPS hanya '
                    .'jika pengguna secara jelas meminta angka, tahun, wilayah, atau tabel.',
            ])));
        }
        $cacheKey = 'chatbot:ai:v2:'.sha1(mb_strtolower(trim($message)).'|'.sha1((string) $context).'|'.sha1(serialize($history)));
        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && trim($cached) !== '') {
                if (! (
                    $this->isStatisticalDataQuestion($message)
                    && $this->isUnverifiedDataReply($cached)
                )) {
                    return $this->cleanDataBearingReply($cached);
                }
                Cache::forget($cacheKey);
                Log::warning('chatbot.cached_unverified_data_reply');
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        try {
            $response = $knowledge->askWithContext($message, $sessionId, $context, $history);
        } catch (ConnectionException $exception) {
            report($exception);

            return null;
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('chatbot.ai_unavailable', ['status' => $response->status()]);

            return null;
        }

        $reply = $response->json('reply');
        if (! is_string($reply)) {
            $reply = $response->json('data');
        }
        if (! is_string($reply) || trim($reply) === '') {
            Log::warning('chatbot.ai_empty_reply');

            return null;
        }
        if (
            $this->isStatisticalDataQuestion($message)
            && $this->isUnverifiedDataReply($reply)
        ) {
            $this->lastAiReplyUnverified = true;
            Log::warning('chatbot.ai_unverified_data_reply');

            return null;
        }
        if ($this->isStatisticalDataQuestion($message)
            && is_string($context)
            && str_contains($context, '[Sumber: BPS WebAPI')
            && $this->hasConcreteDataEvidence($context)
            && ! $this->hasConcreteDataEvidence($reply)) {
            $this->lastAiReplyUnverified = true;
            Log::warning('chatbot.ai_reply_omitted_verified_bps_data');

            return null;
        }

        $reply = $this->cleanDataBearingReply($reply);
        try {
            Cache::put($cacheKey, $reply, now()->addMinutes(20));
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $reply;
    }

    private function isStatisticalDataQuestion(string $message): bool
    {
        if ($this->isExplanatoryQuestion($message)) {
            return false;
        }

        $hasValueCue = preg_match(
            '/\b(berapa|jumlah|total|nilai|angka|persen\w*|persentase|tingkat|laju|terbaru|terkini|tahun|periode|data|indikator|statistik|informasi|katalog|bencana)\b|\b20\d{2}\b/iu',
            $message
        ) === 1;
        if ($this->isConceptQuestion($message) && ! $hasValueCue) {
            return false;
        }

        return $hasValueCue;
    }

    private function isUnverifiedDataReply(string $reply): bool
    {
        if ($this->hasConcreteDataEvidence($reply)) {
            return false;
        }

        $requestedPeriodUnavailable = preg_match(
            '/\b20\d{2}\b[^.!?\n]{0,80}\b(?:belum\s+(?:dapat\s*)?(?:tersedia|dirilis|ditemukan)|'
            .'tidak\s+(?:dapat\s+)?(?:tersedia|dirilis|ditemukan))\b/iu',
            $reply
        ) === 1;
        $latestPeriodProvided = preg_match(
            '/\b(?:terbaru|terakhir|tersedia|berhasil\s+diverifikasi)\b[^.!?\n]{0,80}\b20\d{2}\b/iu',
            $reply
        ) === 1;

        if ($requestedPeriodUnavailable && $latestPeriodProvided) {
            return false;
        }

        return preg_match(
            '/\b(?:belum\s+(?:dapat\s*)?(?:ditemukan|tersedia|terverifikasi|diverifikasi|dirilis|dipastikan|diperoleh|'
            .'tercantum|dijawab)|tidak\s+(?:dapat\s+)?(?:ditemukan|tersedia|diverifikasi|diakses|dipastikan)|'
            .'tidak\s+ada\s+(?:data|informasi)|belum\s+ada\s+(?:data|informasi))\b/iu',
            $reply
        ) === 1;
    }

    private function hasConcreteDataEvidence(string $reply): bool
    {
        if (preg_match(
            '/\b\d[\d.,]*\s*(?:%|\b(?:persen|jiwa|orang|unit|rupiah|miliar|juta|ribu)\b)/iu',
            $reply
        ) === 1) {
            return true;
        }

        if (preg_match_all('/(?:^|[:|]\s*)(?:Rp\s*)?(\d[\d.,]*)\b/mi', $reply, $matches) === false) {
            return false;
        }

        foreach ($matches[1] ?? [] as $number) {
            $digits = preg_replace('/\D/u', '', $number) ?? '';
            if ($digits === '' || (strlen($digits) === 4 && str_starts_with($digits, '20'))) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function cleanDataBearingReply(string $reply): string
    {
        if (! $this->hasConcreteDataEvidence($reply)) {
            return $reply;
        }

        $cleaned = preg_replace(
            '/\A\s*Maaf,\s*saya\s+belum\s+menemukan\s+indikator\s+khusus\b.*?\bNamun,\s*(?:(?:ada|tersedia)\s+)?data\s*/isu',
            'Data tersedia: ',
            $reply,
            1
        );

        return is_string($cleaned) ? trim($cleaned) : $reply;
    }

    private function knowledgeContextFor(ChatbotKnowledgeService $knowledge, string $message): ?string
    {
        try {
            return $knowledge->contextFor($message);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function chatContextFor(
        ChatbotKnowledgeService $knowledge,
        BpsWebApiService $bps,
        string $message
    ): ?string {
        $contexts = array_filter([
            $this->knowledgeContextFor($knowledge, $message),
        ], static fn (?string $context) => filled($context));

        if (! $this->isConceptQuestion($message)
            && ! $this->isExplanatoryQuestion($message)
            && $bps->isDataQuestion($message)) {
            $bpsContext = $bps->contextFor($message);
            if (filled($bpsContext)) {
                $contexts[] = $bpsContext;
            }
        }

        if ($contexts === []) {
            return null;
        }

        return Str::limit(implode("\n\n", $contexts), 18000, '...');
    }

    private function conversationHistory(?ChatbotConversation $conversation): array
    {
        try {
            return $conversation?->messages()
                ->latest('id')
                ->limit(6)
                ->get(['prompt', 'response'])
                ->reverse()
                ->map(fn ($turn) => [
                    'prompt' => mb_substr($turn->prompt, 0, 4000),
                    'response' => mb_substr($turn->response, 0, 6000),
                ])
                ->values()
                ->all() ?? [];
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function fallbackAnswer(
        BpsWebApiService $bps,
        string $message,
        ?string $context = null,
        bool $aiReplyUnverified = false,
        array $history = []
    ): string
    {
        if ($this->isAdviceQuestion($message)) {
            return $this->generalAdviceFallback();
        }

        if ($this->isConceptQuestion($message)) {
            if (filled($context)) {
                return "Berikut penjelasan dari basis pengetahuan BPS yang tersedia:\n\n"
                    .Str::limit(trim($context), 4000, '...');
            }

            $percentageExplanation = $this->percentageExplanationFallback($message, $history);
            if ($percentageExplanation !== null) {
                return $percentageExplanation;
            }

            if ($this->isExplanatoryQuestion($message)) {
                return $this->explanatoryFallbackAnswer($message);
            }

            return 'Maaf, penjelasan konsep tersebut belum tersedia pada basis pengetahuan saat ini. '
                .'Silakan coba lagi setelah referensi yang sesuai tersedia.';
        }

        try {
            $answer = $bps->fallbackAnswerFor($message);
            if ($aiReplyUnverified) {
                return Str::replaceFirst(
                    config('chatbot_fallback.notice'),
                    'Saya periksa kembali menggunakan data resmi BPS:',
                    $answer
                );
            }

            return $answer;
        } catch (\Throwable $exception) {
            report($exception);

            return config('chatbot_fallback.notice')."\n\n".config('chatbot_fallback.closing');
        }
    }

    private function appendTrustedSources(
        string $reply,
        ?string $context,
        BpsWebApiService $bps,
        string $question
    ): string {
        if (! is_string($context) || ! str_contains($context, '[Sumber:')) {
            return $reply;
        }

        $sourceLines = $bps->sourceLinesForContext($context, $question);
        if ($sourceLines === '') {
            return $reply;
        }

        $lines = [];
        $inSourceSection = false;
        foreach (preg_split('/\R/u', $reply) ?: [] as $line) {
            if (preg_match('/^\s*Sumber\s*:/iu', $line)) {
                $inSourceSection = true;
                continue;
            }
            if ($inSourceSection) {
                if (trim($line) === '') {
                    $inSourceSection = false;
                    continue;
                }
                if (preg_match('/^\s*(?:[-*+]\s+|https?:\/\/)/iu', $line)) {
                    continue;
                }
                $inSourceSection = false;
            }

            $line = preg_replace(
                '/\[([^\]]+)\]\(\s*https?:\/\/[^)]+\)/iu',
                '$1',
                $line
            ) ?? $line;
            $line = preg_replace('~https?://[^\s<>()]+~iu', '', $line) ?? $line;
            $lines[] = rtrim($line);
        }

        $cleanReply = trim(implode("\n", $lines));
        return $cleanReply === ''
            ? $sourceLines
            : $cleanReply."\n\n".$sourceLines;
    }

    private function isAdviceQuestion(string $message): bool
    {
        return preg_match(
            '/\b(saran|nasihat|rekomendasi|anjuran|tips|masukan|panduan|petunjuk)\b/iu',
            $message
        ) === 1;
    }

    private function generalAdviceFallback(): string
    {
        return 'Tentu, saya bisa membantu. Untuk menggunakan data statistik dengan tepat, tentukan dulu '
            .'pertanyaan atau tujuan Anda, lalu pilih indikator yang sesuai. Periksa definisi indikator, '
            .'wilayah, periode, satuan, dan sumbernya sebelum membandingkan angka. Jika Anda memberi tahu '
            .'topik, wilayah, dan tujuan penggunaan datanya, saya dapat membantu menyusun langkah atau '
            .'merekomendasikan jenis data yang perlu dicari.';
    }

    private function percentageExplanationFallback(string $message, array $history): ?string
    {
        if (preg_match('/\b(maksud(?:nya)?|artinya|arti|makna|jelaskan|bagaimana|gimana)\b/iu', $message) !== 1) {
            return null;
        }

        $previousResponses = collect($history)
            ->reverse()
            ->pluck('response')
            ->filter(fn ($response) => is_string($response) && trim($response) !== '');
        $previousAnswer = $previousResponses->first(
            fn (string $response) => preg_match('/\d+(?:[.,]\d+)?\s*(?:%|\bpersen\b)/iu', $response) === 1
        );
        if (! is_string($previousAnswer)) {
            return null;
        }

        $numberPattern = '/(?<![\d.,])(\d{1,3}(?:[.,]\d{1,3})?)\s*(?:%|\bpersen\b)/iu';
        preg_match($numberPattern, $message, $questionMatch);
        preg_match($numberPattern, $previousAnswer, $answerMatch);
        $number = $questionMatch[1] ?? $answerMatch[1] ?? null;
        if (! is_string($number)) {
            return null;
        }

        $percentage = (float) str_replace(',', '.', $number);
        if ($percentage < 0 || $percentage > 100) {
            return null;
        }

        $formatted = str_replace('.', ',', $number);
        $perHundred = (int) round($percentage);
        $basis = 'unit yang diukur';
        if (preg_match('/\b(rumah\s+tangga|penduduk|orang|usaha|perusahaan|angkatan\s+kerja)\b/iu', $previousAnswer, $basisMatch) === 1) {
            $basis = mb_strtolower($basisMatch[1]);
        }
        $year = preg_match('/\b20\d{2}\b/', $previousAnswer, $yearMatch) === 1
            ? ' pada tahun '.$yearMatch[0]
            : '';

        return "Angka {$formatted} persen berarti dari setiap 100 {$basis} dalam cakupan data, "
            ."sekitar {$perHundred} termasuk kelompok yang disebut pada indikator{$year}. "
            ."Ini adalah proporsi, bukan jumlah absolut; jumlah pastinya memerlukan total {$basis} "
            .'yang menjadi dasar pengukuran.';
    }

    private function explanatoryFallbackAnswer(string $message): string
    {
        if (preg_match('/\b(penganggur\w*|tpt|ketenagakerjaan|tenaga\s+kerja)\b/iu', $message) === 1) {
            return 'Beberapa kemungkinan yang dapat ditelaah antara lain ketidaksesuaian keterampilan '
                .'pencari kerja dengan lowongan, terbatasnya kesempatan kerja di sektor atau wilayah tertentu, '
                .'perubahan musiman dan struktur ekonomi, serta masa transisi dari pendidikan ke pekerjaan. '
                .'TPT mengukur proporsi penganggur dalam angkatan kerja; angka 3,69 persen yang disebutkan '
                .'tidak dengan sendirinya menjelaskan penyebabnya atau membuktikan faktor tertentu. '
                .'Untuk menyimpulkan penyebab khusus Sumatera Selatan pada 2025, diperlukan data tambahan '
                .'seperti karakteristik penganggur, sektor pekerjaan, pendidikan, dan tren antarperiode.';
        }

        return 'Angka statistik menunjukkan besaran suatu indikator, tetapi tidak dengan sendirinya '
            .'membuktikan penyebabnya. Kemungkinan faktor perlu ditelaah bersama karakteristik kelompok '
            .'yang diukur, kondisi wilayah, perubahan antarperiode, dan definisi indikator. '
            .'Penjelasan tersebut merupakan kemungkinan untuk diuji, bukan kesimpulan kausal dari satu angka.';
    }

    private function isConceptQuestion(string $message): bool
    {
        if ($this->isExplanatoryQuestion($message)) {
            return true;
        }

        if (preg_match(
            '/\b(apa\s+itu|apa\s+artinya|apa\s+makna|apa\s+maksud(?:nya)?|apa\s+yang\s+dimaksud(?:\s+dengan)?|'
            .'apa\s+perbedaan|perbedaan|pengertian|definisi|arti|makna|maksud(?:nya)?|konsep|cara\s+membaca|'
            .'cara\s+menghitung|cara\s+menafsir\w*|rumus|jelaskan|fungsi|kegunaan|tujuan|manfaat)\b'
            .'|\b[\p{L}\d][\p{L}\d -]{1,80}\s+itu(?:\s+[\p{L}\d-]+){0,4}\s+(?:apa|bagaimana|seperti\s+apa)\b/iu',
            $message
        ) !== 1) {
            return false;
        }
        if (preg_match('/\b(berapa|nilai|angka|terbaru|terkini|tahun|periode|rilis)\b|\b20\d{2}\b/iu', $message)        ) {
            return false;
        }
        return true;
    }
    private function isExplanatoryQuestion(string $message): bool
    {
        return preg_match(
            '/\b(mengapa|kenapa|penyebab|alasan|faktor|dampak|implikasi)\b/iu',
            $message
        ) === 1;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

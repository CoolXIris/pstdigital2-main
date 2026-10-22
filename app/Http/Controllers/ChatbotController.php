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

        return view('admin.chatbot.index', [
            'sources' => $sources,
            'sourceCount' => ChatbotKnowledgeSource::count(),
            'chunkCount' => \App\Models\ChatbotKnowledgeChunk::count(),
            'lastTrainedAt' => ChatbotKnowledgeSource::max('trained_at'),
            'bpsApiKeyConfigured' => $bps->hasApiKey(),
        ]);
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

        $reply = $safetyResult['reply']
            ?? $aiReply
            ?? $this->fallbackAnswer(
                $bps,
                $validated['message'],
                $context,
                $this->lastAiReplyUnverified
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
        bool $aiReplyUnverified = false
    ): string
    {
        if ($this->isConceptQuestion($message)) {
            if (filled($context)) {
                return "Berikut penjelasan dari basis pengetahuan BPS yang tersedia:\n\n"
                    .Str::limit(trim($context), 4000, '...');
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
            '/\b(apa\s+itu|apa\s+artinya|apa\s+makna|apa\s+perbedaan|perbedaan|pengertian|definisi|arti|makna|'
            .'maksud(?:nya)?|konsep|cara\s+membaca|cara\s+menghitung|cara\s+menafsir\w*|rumus|jelaskan|itu\s+apa)\b/iu',
            $message
        ) !== 1) {
            return false;
        }
        if (preg_match('/\b(berapa|nilai|angka|terbaru|terkini|tahun|periode|rilis)\b|\b20\d{2}\b/iu', $message)) {
            return false;
        }
        return true;
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

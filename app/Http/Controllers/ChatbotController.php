<?php

namespace App\Http\Controllers;

use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledgeSource;
use App\Services\BpsWebApiService;
use App\Services\ChatbotKnowledgeService;
use App\Services\ChatbotSafetyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatbotController extends Controller
{
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

    public function saveBpsApiKey(Request $request, BpsWebApiService $bps): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:500'],
            'clear_api_key' => ['nullable', 'boolean'],
        ]);

        if ($validated['clear_api_key'] ?? false) {
            $bps->clearApiKey();

            return back()->with('message', 'API key WebAPI BPS berhasil dihapus.');
        }

        if (! filled($validated['api_key'] ?? null)) {
            return back()->with('error', 'Masukkan API key baru atau pilih opsi hapus API key.');
        }

        $bps->saveApiKey($validated['api_key']);

        return back()->with('message', 'API key WebAPI BPS berhasil disimpan secara terenkripsi.');
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

    public function testMessage(Request $request, ChatbotKnowledgeService $knowledge, BpsWebApiService $bps, ChatbotSafetyService $safety): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return $this->sendMessageToService($validated['message'], 'admin-test-'.Auth::id(), $knowledge, $bps, $safety);
    }

    public function sendMessage(Request $request, ChatbotKnowledgeService $knowledge, BpsWebApiService $bps, ChatbotSafetyService $safety): JsonResponse
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
        $safetyViolation = $safety->check($validated['message']);

        try {
            if ($safetyViolation !== null) {
                $reply = $safetyViolation['reply'];
                $knowledgeUsed = false;
            } else {
                $directReply = $bps->catalogAnswerFor($validated['message']);
                $context = $directReply === null ? $this->combinedContext($validated['message'], $knowledge, $bps) : null;
                $previousTurn = $conversation?->messages()->latest('id')->first(['prompt', 'response']);
                $previousAnswer = $previousTurn !== null && $this->isFollowUpQuestion($validated['message'], $previousTurn->prompt)
                    ? $previousTurn->response
                    : null;
                if ($directReply !== null) {
                    $reply = $directReply;
                } else {
                    $response = $knowledge->askWithContext($validated['message'], (string) Str::uuid(), $context, $previousAnswer);
                    if (! $response->successful()) {
                        return response()->json(['message' => 'Layanan chatbot merespons dengan status '.$response->status().'.'], 502);
                    }

                    $reply = $response->json('data');
                    if (! is_string($reply) || trim($reply) === '') {
                        return response()->json(['message' => 'Layanan merespons, tetapi format jawabannya tidak dikenali.'], 502);
                    }
                    if ($previousAnswer !== null && $this->sameAnswer($reply, $previousAnswer)) {
                        $reply = 'Informasi yang saya sampaikan sebelumnya masih berlaku. Saya bisa menjelaskannya dengan lebih ringkas atau membandingkannya dengan periode lain.';
                    }
                }
                $knowledgeUsed = $context !== null || $directReply !== null;
            }

            $conversation ??= ChatbotConversation::create([
                'user_id' => $user->id,
                'session_key' => $sessionKey,
                'title' => Str::limit(trim($validated['message']), 180, '...'),
            ]);
            $conversation->messages()->create([
                'prompt' => $validated['message'],
                'response' => $reply,
                'knowledge_used' => $knowledgeUsed,
            ]);
            $conversation->touch();

            return response()->json([
                'reply' => $reply,
                'knowledge_used' => $knowledgeUsed,
                'safety_blocked' => $safetyViolation !== null,
                'conversation_id' => $conversation->id,
                'title' => $conversation->title,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Tidak dapat menghubungi layanan chatbot.'], 502);
        }
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

    private function sendMessageToService(string $message, string $sessionId, ChatbotKnowledgeService $knowledge, BpsWebApiService $bps, ChatbotSafetyService $safety): JsonResponse
    {
        $safetyViolation = $safety->check($message);
        if ($safetyViolation !== null) {
            return response()->json([
                'reply' => $safetyViolation['reply'],
                'knowledge_used' => false,
                'safety_blocked' => true,
            ]);
        }

        try {
            $directReply = $bps->catalogAnswerFor($message);
            $context = $directReply === null ? $this->combinedContext($message, $knowledge, $bps) : null;
            if ($directReply !== null) {
                $reply = $directReply;
            } else {
                $response = $knowledge->askWithContext($message, $sessionId, $context);
                if (! $response->successful()) {
                    return response()->json(['message' => 'Layanan chatbot merespons dengan status '.$response->status().'.'], 502);
                }

                $reply = $response->json('data');
                if (! is_string($reply) || trim($reply) === '') {
                    return response()->json(['message' => 'Layanan merespons, tetapi format jawabannya tidak dikenali.'], 502);
                }
            }

            return response()->json(['reply' => $reply, 'knowledge_used' => $context !== null || $directReply !== null]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Tidak dapat menghubungi layanan chatbot.'], 502);
        }
    }

    private function combinedContext(string $message, ChatbotKnowledgeService $knowledge, BpsWebApiService $bps): ?string
    {
        $contexts = array_filter([
            $knowledge->contextFor($message),
            $bps->contextFor($message),
        ]);

        return $contexts === [] ? null : implode("\n\n", $contexts);
    }

    private function sameAnswer(string $reply, string $previousAnswer): bool
    {
        $normalize = fn (string $answer) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($answer))) ?? trim($answer);

        return $normalize($reply) === $normalize($previousAnswer);
    }

    private function isFollowUpQuestion(string $question, string $previousQuestion): bool
    {
        $normalize = fn (string $text) => preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower(trim($text))) ?? trim($text);
        if ($normalize($question) === $normalize($previousQuestion)) {
            return true;
        }

        return preg_match('/\b(itu|tersebut|tadi|sebelumnya|contoh|rinci|maksud|lanjutkan|lanjut|bandingkan|dibandingkan)\b/u', mb_strtolower($question)) === 1
            || preg_match('/\b(bagaimana dengan|kalau begitu)\b/u', mb_strtolower($question)) === 1;
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

<?php

namespace App\Http\Controllers;

use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledgeSource;
use App\Services\BpsWebApiService;
use App\Services\ChatbotKnowledgeService;
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

    public function testMessage(Request $request, ChatbotKnowledgeService $knowledge, BpsWebApiService $bps): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return $this->sendMessageToService($validated['message'], (string) Str::uuid(), $knowledge, $bps);
    }

    public function sendMessage(Request $request, ChatbotKnowledgeService $knowledge, BpsWebApiService $bps): JsonResponse
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

        try {
            $context = $this->knowledgeContextFor($knowledge, $validated['message']);
            $history = $this->conversationHistory($conversation);
            $aiReply = $this->askAi($knowledge, $validated['message'], $sessionKey, $context, $history);
        } catch (\Throwable $exception) {
            report($exception);
            $aiReply = null;
        }

        $reply = $aiReply ?? $this->fallbackAnswer($bps, $validated['message']);
        $knowledgeUsed = $aiReply !== null && $context !== null;
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
            'safety_blocked' => false,
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

    private function sendMessageToService(string $message, string $sessionId, ChatbotKnowledgeService $knowledge, BpsWebApiService $bps): JsonResponse
    {
        try {
            $context = $this->knowledgeContextFor($knowledge, $message);
            $aiReply = $this->askAi($knowledge, $message, $sessionId, $context);
            $reply = $aiReply ?? $this->fallbackAnswer($bps, $message);

            return response()->json([
                'reply' => $reply,
                'knowledge_used' => $aiReply !== null && $context !== null,
                'safety_blocked' => false,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Tidak dapat menghubungi layanan chatbot.'], 502);
        }
    }

    private function askAi(ChatbotKnowledgeService $knowledge, string $message, string $sessionId, ?string $context, array $history = []): ?string
    {
        $cacheKey = 'chatbot:ai:'.sha1(mb_strtolower(trim($message)).'|'.sha1((string) $context).'|'.sha1(serialize($history)));
        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && trim($cached) !== '') {
                return $cached;
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

        try {
            Cache::put($cacheKey, $reply, now()->addMinutes(20));
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $reply;
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

    private function fallbackAnswer(BpsWebApiService $bps, string $message): string
    {
        try {
            return $bps->fallbackAnswerFor($message);
        } catch (\Throwable $exception) {
            report($exception);

            return config('chatbot_fallback.notice')."\n\n".config('chatbot_fallback.closing');
        }
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

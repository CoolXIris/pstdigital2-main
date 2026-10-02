<?php

namespace App\Http\Controllers;

use App\Models\ChatbotKnowledgeSource;
use App\Services\ChatbotKnowledgeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ChatbotController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $user  = Auth::user();

        return view('chatbot.index', compact('user'));
    }

    public function management(): View
    {
        $sources = ChatbotKnowledgeSource::with('uploader:id,name')->latest()->paginate(10);

        return view('admin.chatbot.index', [
            'sources' => $sources,
            'sourceCount' => ChatbotKnowledgeSource::count(),
            'chunkCount' => \App\Models\ChatbotKnowledgeChunk::count(),
            'lastTrainedAt' => ChatbotKnowledgeSource::max('trained_at'),
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

    public function testMessage(Request $request, ChatbotKnowledgeService $knowledge): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return $this->sendMessageToService($validated['message'], 'admin-test-'.Auth::id(), $knowledge);
    }

    public function sendMessage(Request $request, ChatbotKnowledgeService $knowledge): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return $this->sendMessageToService($validated['message'], (string) Auth::id(), $knowledge);
    }

    private function sendMessageToService(string $message, string $sessionId, ChatbotKnowledgeService $knowledge): JsonResponse
    {
        try {
            $context = $knowledge->contextFor($message);
            $response = $knowledge->askWithContext($message, $sessionId, $context);
            if (!$response->successful()) {
                return response()->json(['message' => 'Layanan chatbot merespons dengan status '.$response->status().'.'], 502);
            }

            $reply = $response->json('data');
            if (!is_string($reply) || trim($reply) === '') {
                return response()->json(['message' => 'Layanan merespons, tetapi format jawabannya tidak dikenali.'], 502);
            }

            return response()->json(['reply' => $reply, 'knowledge_used' => $context !== null]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Tidak dapat menghubungi layanan chatbot.'], 502);
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

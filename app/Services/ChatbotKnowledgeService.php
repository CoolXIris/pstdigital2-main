<?php

namespace App\Services;

use App\Models\ChatbotKnowledgeChunk;
use App\Models\ChatbotKnowledgeSource;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class ChatbotKnowledgeService
{
    public function ingest(UploadedFile $file, string $title, User $uploader): ChatbotKnowledgeSource
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $content = match ($extension) {
            'txt', 'md' => $file->get(),
            'csv' => $this->readCsv($file),
            'xlsx' => $this->readSpreadsheet($file),
            default => throw new RuntimeException('Format file tidak didukung.'),
        };

        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
        if ($content === '') {
            throw new RuntimeException('Dokumen tidak memiliki teks yang dapat diindeks.');
        }
        if (Str::length($content) > 200000) {
            throw new RuntimeException('Teks dokumen terlalu panjang untuk diproses. Batasnya 200.000 karakter.');
        }

        $path = $file->store('chatbot-knowledge', 'local');
        $source = ChatbotKnowledgeSource::create([
            'title' => $title,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $extension,
            'size_bytes' => $file->getSize(),
            'added_by' => $uploader->id,
        ]);

        try {
            $chunks = $this->splitIntoChunks($content);
            foreach ($chunks as $index => $chunk) {
                $source->chunks()->create(['chunk_index' => $index, 'content' => $chunk]);
            }
            $source->update(['chunks_count' => count($chunks), 'trained_at' => now()]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            $source->delete();
            throw $exception;
        }

        return $source;
    }

    public function contextFor(string $question): ?string
    {
        $terms = collect($this->terms($question))->unique()->values();
        if ($terms->isEmpty()) {
            return null;
        }

        $matches = ChatbotKnowledgeChunk::with('source:id,title')
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(function (ChatbotKnowledgeChunk $chunk) use ($terms) {
                $text = Str::lower($chunk->content);
                $score = 0;
                foreach ($terms as $term) {
                    $score += substr_count($text, $term) * min(Str::length($term), 8);
                }
                $chunk->setAttribute('relevance_score', $score);

                return $chunk;
            })
            ->filter(fn (ChatbotKnowledgeChunk $chunk) => $chunk->getAttribute('relevance_score') > 0)
            ->sortByDesc(fn (ChatbotKnowledgeChunk $chunk) => $chunk->getAttribute('relevance_score'))
            ->take(5);

        if ($matches->isEmpty()) {
            return null;
        }

        return $matches->map(fn (ChatbotKnowledgeChunk $chunk) => '[Sumber: '.$chunk->source->title.'] '.$chunk->content)->implode("\n\n");
    }

    public function ask(string $question, string $sessionId): Response
    {
        return $this->askWithContext($question, $sessionId, $this->contextFor($question));
    }

    public function askWithContext(string $question, string $sessionId, ?string $context, array $history = []): Response
    {
        // PHP's default max_execution_time (commonly 30s) can be shorter than the HTTP
        // client timeout below, causing a fatal timeout that returns an empty response
        // body (surfacing as "Unexpected end of JSON input" in the browser) instead of
        // the catchable exception the controller expects. Extend it so the HTTP client's
        // own timeout always has a chance to resolve first.
        if (function_exists('set_time_limit')) {
            @set_time_limit(75);
        }

        $request = Http::timeout(60);
        if (filled(config('services.gemini.chat_token'))) {
            $request = $request->withToken(config('services.gemini.chat_token'));
        }

        return $request->post(config('services.gemini.chat_url'), [
            'question' => $question,
            'context' => $context,
            'history' => $history,
            'session_id' => $sessionId,
        ]);
    }

    public function deleteSource(ChatbotKnowledgeSource $source): void
    {
        Storage::disk('local')->delete($source->file_path);
        $source->delete();
    }

    private function readCsv(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new RuntimeException('File CSV tidak dapat dibaca.');
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = implode(' | ', array_map(fn ($value) => (string) $value, $row));
        }
        fclose($handle);

        return implode("\n", $rows);
    }

    private function readSpreadsheet(UploadedFile $file): string
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $lines = [];
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $lines[] = 'Lembar: '.$worksheet->getTitle();
            foreach ($worksheet->toArray(null, true, true, false) as $row) {
                $values = array_map(fn ($value) => (string) ($value ?? ''), $row);
                if (trim(implode('', $values)) !== '') {
                    $lines[] = implode(' | ', $values);
                }
            }
        }

        return implode("\n", $lines);
    }

    private function splitIntoChunks(string $content): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\R/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [$content];
        $chunks = [];
        $current = '';
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            while (Str::length($sentence) > 1200) {
                $chunks[] = Str::substr($sentence, 0, 1200);
                $sentence = Str::substr($sentence, 1200);
            }
            if ($current !== '' && Str::length($current) + Str::length($sentence) > 1200) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : ' ').$sentence;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function terms(string $text): array
    {
        preg_match_all('/[\pL\pN]{3,}/u', Str::lower($text), $matches);
        $stopWords = ['yang', 'dan', 'atau', 'untuk', 'dari', 'dengan', 'pada', 'dalam', 'adalah', 'berapa', 'bagaimana', 'apa', 'data', 'saya', 'kami', 'bisa', 'tolong'];

        return array_values(array_diff($matches[0] ?? [], $stopWords));
    }
}

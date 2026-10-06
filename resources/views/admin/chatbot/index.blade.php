@extends('layout.admin-rebrand')

@section('title', 'Manajemen Chatbot | PST Digital')
@section('topbar_title', 'Manajemen chatbot')

@section('content')
<section class="admin-page-heading">
    <div>
        <span class="admin-eyebrow">Asisten layanan digital</span>
        <h1>Manajemen Chatbot</h1>
        <p>Kelola referensi statistik yang digunakan chatbot saat menjawab riset dan konsultasi data.</p>
    </div>
    <span class="admin-badge admin-badge-green"><span class="admin-status-dot"></span> Retrieval pengetahuan aktif</span>
</section>

<div class="admin-stat-grid">
    <article class="admin-stat admin-stat-blue"><span class="admin-stat-label">Sumber pengetahuan</span><strong class="admin-stat-value">{{ number_format($sourceCount) }}</strong><span class="admin-stat-icon"><i class="fe fe-database"></i></span></article>
    <article class="admin-stat admin-stat-green"><span class="admin-stat-label">Potongan terindeks</span><strong class="admin-stat-value">{{ number_format($chunkCount) }}</strong><span class="admin-stat-icon"><i class="fe fe-layers"></i></span></article>
    <article class="admin-stat admin-stat-amber"><span class="admin-stat-label">Pembaruan terakhir</span><strong class="admin-stat-value" style="font-size:16px">{{ $lastTrainedAt ? \Illuminate\Support\Carbon::parse($lastTrainedAt)->translatedFormat('d M Y') : 'Belum ada' }}</strong><span class="admin-stat-icon"><i class="fe fe-refresh-cw"></i></span></article>
    <article class="admin-stat admin-stat-red"><span class="admin-stat-label">Mekanisme</span><strong class="admin-stat-value" style="font-size:16px">RAG</strong><span class="admin-stat-foot">Referensi relevan disertakan saat chat</span><span class="admin-stat-icon"><i class="fe fe-search"></i></span></article>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <section class="admin-panel h-100">
            <div class="admin-panel-head">
                <div>
                    <h2 class="admin-panel-title">Tambah data pengetahuan</h2>
                    <p class="admin-panel-subtitle">Impor data statistik atau dokumen rujukan baru.</p>
                </div>
            </div>
            <div class="admin-panel-body">
                <form method="POST" action="{{ route('admin.chatbot.knowledge.store') }}" enctype="multipart/form-data" class="admin-knowledge-upload">
                    @csrf
                    <div class="mb-3"><label class="form-label" for="knowledge-title">Nama dataset / sumber</label><input class="form-control" id="knowledge-title" name="title" value="{{ old('title') }}" maxlength="150" placeholder="Contoh: Indikator sosial ekonomi 2025" required></div>
                    <div class="mb-3"><label class="form-label" for="knowledge-file">Berkas sumber</label><input class="form-control" id="knowledge-file" name="file" type="file" accept=".txt,.md,.csv,.xlsx" required><small class="form-text">Format TXT, Markdown, CSV, XLSX · maksimal 20 MB</small></div>
                    <button class="admin-btn admin-btn-primary" type="submit"><i class="fe fe-upload"></i> Indeks &amp; perbarui pengetahuan</button>
                </form>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <aside class="admin-knowledge-explainer h-100">
            <span class="admin-knowledge-symbol"><i class="fe fe-cpu"></i></span>
            <h2>Bagaimana pembaruan dipakai?</h2>
            <p>Berkas diekstrak dan dipecah menjadi referensi. Pertanyaan pengguna dicocokkan dengan referensi yang relevan, lalu konteksnya diteruskan ke layanan chatbot.</p>
            <div><i class="fe fe-shield"></i><span>Dokumen tersimpan privat di server dan tidak disajikan sebagai tautan publik.</span></div>
            <div><i class="fe fe-info"></i><span>Ini retrieval-augmented generation (RAG), bukan fine-tuning bobot model eksternal.</span></div>
        </aside>
    </div>
</div>

<section class="admin-panel mb-3">
    <div class="admin-panel-head">
        <div>
            <h2 class="admin-panel-title">Sumber pengetahuan aktif</h2>
            <p class="admin-panel-subtitle">Referensi di bawah akan dipakai pada percakapan yang cocok.</p>
        </div>
    </div>
    <div class="admin-table-wrap mt-3">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Nama sumber</th>
                    <th>Format</th>
                    <th>Potongan</th>
                    <th>Diperbarui</th>
                    <th>Ditambahkan oleh</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sources as $source)
                <tr>
                    <td>
                        <div class="admin-person"><span class="admin-person-avatar"><i class="fe fe-file-text"></i></span><span><strong>{{ $source->title }}</strong><small>{{ $source->original_name }} · {{ number_format($source->size_bytes / 1024, 0) }} KB</small></span></div>
                    </td>
                    <td><span class="admin-badge admin-badge-neutral">{{ strtoupper($source->file_type) }}</span></td>
                    <td>{{ number_format($source->chunks_count) }}</td>
                    <td>{{ $source->trained_at?->translatedFormat('d M Y, H:i') ?? '—' }}</td>
                    <td>{{ $source->uploader?->name ?? 'Admin' }}</td>
                    <td>
                        <form class="admin-inline-form" method="POST" action="{{ route('admin.chatbot.knowledge.destroy', $source) }}" onsubmit="return confirm('Hapus sumber dan seluruh indeks pengetahuannya?')">@csrf @method('DELETE')<button class="admin-action-icon is-danger" type="submit" title="Hapus sumber" aria-label="Hapus {{ $source->title }}"><i class="fe fe-trash-2"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6">
                        <div class="admin-empty"><i class="fe fe-book-open"></i><strong>Belum ada sumber pengetahuan</strong><span>Tambahkan dataset atau dokumen rujukan untuk mulai memperkaya jawaban chatbot.</span></div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($sources->hasPages())<div class="admin-table-footer"><span>{{ $sources->total() }} sumber</span>
        <div class="admin-pagination">{{ $sources->links() }}</div>
    </div>@endif
</section>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <section class="admin-panel h-100">
            <div class="admin-panel-head">
                <div>
                    <h2 class="admin-panel-title">Uji percakapan</h2>
                    <p class="admin-panel-subtitle">Pesan uji berjalan pada sesi admin terpisah.</p>
                </div>
            </div>
            <div class="admin-panel-body pt-3">
                <div class="admin-chat-test admin-panel">
                    <div class="admin-chat-log" id="chat-test-log" aria-live="polite">
                        <div class="admin-chat-bubble">Mulai uji layanan dengan mengirim pertanyaan singkat.</div>
                    </div>
                    <form class="admin-chat-compose" id="chat-test-form" action="{{ route('admin.chatbot.test') }}">
                        <textarea class="form-control" name="message" id="chat-test-message" maxlength="2000" placeholder="Tulis pesan untuk menguji chatbot..." aria-label="Pesan uji chatbot" required></textarea>
                        <button class="admin-btn admin-btn-primary" type="submit" id="chat-test-submit"><i class="fe fe-send"></i><span>Kirim</span></button>
                    </form>
                </div>
            </div>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="admin-panel mb-3">
            <div class="admin-panel-head">
                <div>
                    <h2 class="admin-panel-title">Status layanan</h2>
                    <p class="admin-panel-subtitle">Koneksi dicek melalui pesan uji.</p>
                </div>
            </div>
            <div class="admin-panel-body">
                <div class="admin-connection mb-3"><span class="admin-connection-indicator" id="chat-status-icon"><i class="fe fe-activity"></i></span><span><strong id="chat-status-title">Belum diperiksa</strong><small id="chat-status-description">Kirim pesan uji untuk mengecek endpoint.</small></span></div>
                <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #edf1f5"><span class="text-muted">Endpoint</span><span class="fw-semibold">pst-chat.bpssumsel.com</span></div>
                <div class="d-flex justify-content-between py-2"><span class="text-muted">Metode</span><span class="fw-semibold">POST /send_message</span></div>
            </div>
        </section>
        <aside class="admin-muted-note"><strong>Uji sumber pengetahuan</strong><br>Percakapan uji menggunakan sesi terpisah. Tanyakan topik yang tercantum pada sumber aktif untuk memastikan referensi terbaru dipakai pada jawaban.</aside>
    </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('chat-markdown.js') }}"></script>
<script>
    (() => {
        const form = document.getElementById('chat-test-form');
        const input = document.getElementById('chat-test-message');
        const log = document.getElementById('chat-test-log');
        const submit = document.getElementById('chat-test-submit');
        const statusTitle = document.getElementById('chat-status-title');
        const statusDescription = document.getElementById('chat-status-description');
        const statusIcon = document.getElementById('chat-status-icon');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

        const addMessage = (text, isUser = false) => {
            const bubble = document.createElement('div');
            bubble.className = `admin-chat-bubble${isUser ? ' is-user' : ''}`;
            if (isUser) {
                bubble.textContent = text;
            } else {
                bubble.classList.add('chat-md');
                bubble.innerHTML = window.renderChatMarkdown(text);
            }
            log.append(bubble);
            log.scrollTop = log.scrollHeight;
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const message = input.value.trim();
            if (!message) return;

            addMessage(message, true);
            input.value = '';
            submit.disabled = true;
            submit.querySelector('span').textContent = 'Memeriksa...';
            statusTitle.textContent = 'Menghubungkan';
            statusDescription.textContent = 'Menunggu respons layanan chatbot.';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({
                        message
                    })
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Layanan tidak tersedia.');
                addMessage(result.reply);
                statusTitle.textContent = 'Merespons normal';
                statusDescription.textContent = `Berhasil diperiksa pada ${new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}.`;
                statusIcon.style.color = 'var(--bps-green)';
                statusIcon.style.background = '#edf5e9';
            } catch (error) {
                addMessage(error.message || 'Terjadi kesalahan saat menghubungi layanan.');
                statusTitle.textContent = 'Perlu diperiksa';
                statusDescription.textContent = 'Periksa jaringan atau konfigurasi layanan eksternal.';
                statusIcon.style.color = 'var(--bps-red)';
                statusIcon.style.background = '#faeaea';
            } finally {
                submit.disabled = false;
                submit.querySelector('span').textContent = 'Kirim';
                input.focus();
            }
        });
    })();
</script>
@endsection

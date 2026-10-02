@extends('layout.user-portal')

@section('title', 'Chatbot Statistik | PST Digital')
@section('topbar_title', 'Konsultasi chatbot')

@section('content')
    <section class="portal-heading">
        <div><span class="portal-eyebrow">Asisten statistik</span><h1>Konsultasi Chatbot</h1><p>Tanyakan data, indikator, atau konsep statistik. Jawaban berbasis referensi yang tersedia untuk bot.</p></div>
        <span class="portal-status is-confirmed"><span class="portal-online"></span> Layanan tersedia</span>
    </section>

    <div class="portal-chat-layout">
        <section class="portal-chat-card" aria-label="Percakapan dengan chatbot">
            <header class="portal-chat-header">
                <span class="portal-chat-avatar"><i class="bi bi-robot"></i></span>
                <span><strong>Chatbot PST BPS Sumatera Selatan</strong><small><span class="portal-online"></span> Asisten informasi statistik</small></span>
                <button class="portal-action-icon ms-auto" id="clear-chat" type="button" aria-label="Bersihkan percakapan" title="Bersihkan percakapan"><i class="bi bi-arrow-counterclockwise"></i></button>
            </header>
            <div class="portal-chat-log" id="portal-chat-log" data-greeting="{{ Auth::user()->name }}" aria-live="polite" aria-relevant="additions">
                <div class="portal-bubble">Halo {{ Auth::user()->name }}. Saya siap membantu menjelaskan data dan konsep statistik. Apa yang ingin Anda cari?</div>
            </div>
            <form class="portal-chat-compose" id="portal-chat-form" action="{{ route('chatbot.message') }}">
                <textarea class="form-control" id="portal-chat-input" rows="1" maxlength="2000" placeholder="Tulis pertanyaan statistik Anda..." aria-label="Pesan untuk chatbot" required></textarea>
                <button class="portal-send" id="portal-chat-send" type="submit" aria-label="Kirim pesan"><i class="bi bi-arrow-up"></i></button>
            </form>
        </section>

        <aside class="portal-chat-aside">
            <div><span class="portal-eyebrow">Mulai bertanya</span><h2>Contoh topik</h2><p>Gunakan pertanyaan yang spesifik agar jawaban lebih terarah.</p></div>
            <ul><li>Bagaimana membaca angka inflasi?</li><li>Apa perbedaan PDRB ADHB dan ADHK?</li><li>Di mana mencari data kemiskinan?</li><li>Bagaimana menentukan indikator penelitian?</li></ul>
            <div class="portal-chat-note"><i class="bi bi-info-circle me-1"></i>Gunakan data resmi BPS sebagai rujukan. Untuk kebutuhan yang memerlukan pembahasan mendalam dengan petugas, ajukan <a href="{{ url('konsultasi') }}">konsultasi virtual</a>.</div>
            <div class="portal-chat-note"><i class="bi bi-shield-check me-1"></i>Jangan kirim kata sandi, nomor identitas, atau informasi pribadi sensitif.</div>
        </aside>
    </div>
@endsection

@section('scripts')
<script>
    (() => {
        const form = document.getElementById('portal-chat-form');
        const input = document.getElementById('portal-chat-input');
        const log = document.getElementById('portal-chat-log');
        const send = document.getElementById('portal-chat-send');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const timestamp = () => new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date());
        const addBubble = (text, role = 'assistant', meta = '') => {
            const bubble = document.createElement('div');
            bubble.className = `portal-bubble${role === 'user' ? ' is-user' : ''}`;
            bubble.textContent = text;
            if (meta) {
                const details = document.createElement('div');
                details.className = 'portal-bubble-meta';
                details.textContent = meta;
                bubble.append(details);
            }
            log.append(bubble);
            log.scrollTop = log.scrollHeight;
            return bubble;
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const message = input.value.trim();
            if (!message || send.disabled) return;
            addBubble(message, 'user', timestamp());
            input.value = '';
            input.style.height = '';
            send.disabled = true;
            send.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            const pending = addBubble('Sedang menyiapkan jawaban...', 'assistant');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ message })
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Chatbot belum dapat menjawab. Coba kembali sebentar lagi.');
                pending.firstChild.textContent = result.reply || 'Maaf, saya belum mendapatkan jawaban untuk pertanyaan tersebut.';
                const details = document.createElement('div');
                details.className = 'portal-bubble-meta';
                details.textContent = `${timestamp()}${result.knowledge_used ? ' · memakai referensi data PST' : ''}`;
                pending.append(details);
            } catch (error) {
                pending.firstChild.textContent = error.message || 'Terjadi kesalahan saat menghubungi layanan.';
                pending.classList.add('is-error');
            } finally {
                send.disabled = false;
                send.innerHTML = '<i class="bi bi-arrow-up"></i>';
                input.focus();
                log.scrollTop = log.scrollHeight;
            }
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = `${Math.min(input.scrollHeight, 130)}px`;
        });
        document.getElementById('clear-chat').addEventListener('click', () => {
            log.replaceChildren();
            addBubble(`Halo ${log.dataset.greeting}. Saya siap membantu menjelaskan data dan konsep statistik. Apa yang ingin Anda cari?`);
            input.focus();
        });
    })();
</script>
@endsection

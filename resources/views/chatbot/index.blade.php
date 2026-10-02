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
                <button class="portal-action-icon ms-auto" id="header-new-chat" type="button" aria-label="Mulai percakapan baru" title="Percakapan baru"><i class="bi bi-plus-lg"></i></button>
            </header>
            <div class="portal-chat-log" id="portal-chat-log" data-greeting="{{ Auth::user()->name }}" aria-live="polite" aria-relevant="additions">
                <div class="portal-bubble">Halo {{ Auth::user()->name }}. Saya siap membantu menjelaskan data dan konsep statistik. Apa yang ingin Anda cari?</div>
            </div>
            <form class="portal-chat-compose" id="portal-chat-form" action="{{ route('chatbot.message') }}">
                <textarea class="form-control" id="portal-chat-input" rows="1" maxlength="2000" placeholder="Tulis pertanyaan statistik Anda..." aria-label="Pesan untuk chatbot" required></textarea>
                <button class="portal-send" id="portal-chat-send" type="submit" aria-label="Kirim pesan"><i class="bi bi-arrow-up"></i></button>
            </form>
        </section>

        <aside class="portal-chat-aside portal-chat-history" aria-label="Riwayat percakapan chatbot">
            <div class="portal-chat-history-heading"><div><span class="portal-eyebrow">Percakapan tersimpan</span><h2>Riwayat Chat</h2></div><button class="portal-chat-new" id="new-chat" type="button" aria-label="Mulai percakapan baru" title="Percakapan baru"><i class="bi bi-plus-lg"></i></button></div>
            <div class="portal-chat-history-list" id="chat-history-list" data-url-template="{{ url('chatbot/conversations/__ID__') }}" aria-live="polite">
                @forelse ($conversations as $conversation)
                    <button class="portal-chat-history-item" type="button" data-conversation-id="{{ $conversation->id }}" data-title="{{ $conversation->title }}">
                        <span class="portal-chat-history-icon"><i class="bi bi-chat-left-text"></i></span>
                        <span class="portal-chat-history-copy"><strong>{{ $conversation->title }}</strong><small>{{ $conversation->updated_at->translatedFormat('d M Y, H:i') }} · {{ $conversation->messages_count }} tanya jawab</small></span>
                    </button>
                @empty
                    <div class="portal-chat-history-empty" id="chat-history-empty"><i class="bi bi-chat-square-dots"></i><span>Belum ada percakapan tersimpan.</span></div>
                @endforelse
            </div>
            <details class="portal-chat-topics"><summary><i class="bi bi-lightbulb"></i> Contoh pertanyaan</summary><ul><li>Bagaimana membaca angka inflasi?</li><li>Apa perbedaan PDRB ADHB dan ADHK?</li><li>Di mana mencari data kemiskinan?</li><li>Bagaimana menentukan indikator penelitian?</li></ul></details>
            <div class="portal-chat-note"><i class="bi bi-info-circle me-1"></i>Gunakan data resmi BPS sebagai rujukan. Untuk pembahasan mendalam dengan petugas, ajukan <a href="{{ url('konsultasi') }}">konsultasi virtual</a>.</div>
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
        const historyList = document.getElementById('chat-history-list');
        const newChat = document.getElementById('new-chat');
        const headerNewChat = document.getElementById('header-new-chat');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        let conversationId = null;
        const timestamp = () => new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date());
        const greeting = () => `Halo ${log.dataset.greeting}. Saya siap membantu menjelaskan data dan konsep statistik. Apa yang ingin Anda cari?`;
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
        const updateActiveHistory = () => {
            historyList.querySelectorAll('.portal-chat-history-item').forEach((item) => {
                item.classList.toggle('is-active', item.dataset.conversationId === String(conversationId));
            });
        };
        const updateHistoryItem = (id, title, count = 1) => {
            let item = historyList.querySelector(`[data-conversation-id="${id}"]`);
            if (!item) {
                historyList.querySelector('#chat-history-empty')?.remove();
                item = document.createElement('button');
                item.type = 'button';
                item.className = 'portal-chat-history-item';
                item.dataset.conversationId = String(id);
                item.innerHTML = '<span class="portal-chat-history-icon"><i class="bi bi-chat-left-text"></i></span><span class="portal-chat-history-copy"><strong></strong><small></small></span>';
                historyList.prepend(item);
            }
            item.dataset.title = title;
            item.querySelector('strong').textContent = title;
            item.querySelector('small').textContent = `${new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date())} · ${count} tanya jawab`;
            historyList.prepend(item);
            updateActiveHistory();
        };
        const startNewConversation = () => {
            if (send.disabled) return;
            conversationId = null;
            log.replaceChildren();
            addBubble(greeting());
            updateActiveHistory();
            input.focus();
        };
        const openConversation = async (item) => {
            if (send.disabled || item.disabled) return;
            const id = item.dataset.conversationId;
            item.disabled = true;
            try {
                const url = historyList.dataset.urlTemplate.replace('__ID__', encodeURIComponent(id));
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Riwayat percakapan tidak dapat dibuka.');
                conversationId = result.id;
                log.replaceChildren();
                result.messages.forEach((message) => {
                    const sentAt = new Date(message.created_at);
                    const sentTime = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(sentAt);
                    addBubble(message.prompt, 'user', sentTime);
                    addBubble(message.response, 'assistant', `${sentTime}${message.knowledge_used ? ' · memakai referensi data PST' : ''}`);
                });
                updateActiveHistory();
            } catch (error) {
                addBubble(error.message || 'Riwayat percakapan tidak dapat dibuka.', 'assistant').classList.add('is-error');
            } finally {
                item.disabled = false;
            }
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
                    body: JSON.stringify({ message, conversation_id: conversationId })
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Chatbot belum dapat menjawab. Coba kembali sebentar lagi.');
                pending.firstChild.textContent = result.reply || 'Maaf, saya belum mendapatkan jawaban untuk pertanyaan tersebut.';
                conversationId = result.conversation_id;
                const existingCount = historyList.querySelector(`[data-conversation-id="${conversationId}"] small`)?.textContent.match(/(\d+) tanya jawab/)?.[1];
                updateHistoryItem(conversationId, result.title, existingCount ? Number(existingCount) + 1 : 1);
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
        historyList.addEventListener('click', (event) => {
            const item = event.target.closest('.portal-chat-history-item');
            if (item) openConversation(item);
        });
        newChat.addEventListener('click', startNewConversation);
        headerNewChat.addEventListener('click', startNewConversation);
    })();
</script>
@endsection

@extends('layout.user-portal')

@section('title', 'Chatbot Statistik | PST Digital')
@section('topbar_title', 'Konsultasi chatbot')

@section('content')
<section class="portal-heading">
    <div><span class="portal-eyebrow">Asisten statistik</span>
        <h1>Konsultasi Chatbot</h1>
        <p>Tanyakan data, indikator, atau konsep statistik. Jawaban berbasis referensi yang tersedia untuk bot.</p>
    </div>
    <span class="portal-status is-confirmed"><span class="portal-online"></span> Layanan tersedia</span>
</section>

<div class="portal-chat-layout">
    <section class="portal-chat-card" aria-label="Percakapan dengan chatbot">
        <header class="portal-chat-header">
            <span class="portal-chat-avatar"><i class="bi bi-robot"></i></span>
            <span><strong>Chatbot PST BPS Sumatera Selatan</strong><small><span class="portal-online"></span> Asisten informasi statistik</small></span>
        </header>
        <div class="portal-chat-log" id="portal-chat-log" data-greeting="{{ Auth::user()->name }}" aria-live="polite" aria-relevant="additions">
            <div class="portal-bubble">Halo {{ Auth::user()->name }}. Saya siap membantu menjelaskan data dan konsep statistik. Apa yang ingin Anda cari?</div>
        </div>
        <p class="portal-chat-warning" role="note">AI dapat keliru. Periksa kembali informasi penting melalui sumber resmi.</p>
        <form class="portal-chat-compose" id="portal-chat-form" action="{{ route('chatbot.message') }}">
            <textarea class="form-control" id="portal-chat-input" rows="1" maxlength="2000" placeholder="Tulis pertanyaan statistik Anda..." aria-label="Pesan untuk chatbot" required></textarea>
            <button class="portal-send" id="portal-chat-send" type="submit" aria-label="Kirim pesan"><i class="bi bi-arrow-up"></i></button>
        </form>
    </section>

    <aside class="portal-chat-aside">
        <div class="portal-chat-history">
            <div class="portal-chat-history-heading">
                <div><span class="portal-eyebrow">Riwayat</span>
                    <h2>Percakapan Anda</h2>
                </div>
                <button class="portal-chat-new" id="new-chat" type="button" aria-label="Mulai percakapan baru" title="Mulai percakapan baru"><i class="bi bi-plus-lg"></i></button>
            </div>
            <div class="portal-chat-history-list" id="chat-history-list">
                @forelse ($conversations as $item)
                    <div class="portal-chat-history-item" role="button" tabindex="0" data-conversation-id="{{ $item->id }}" aria-label="Buka percakapan {{ $item->title ?: 'tanpa judul' }}">
                        <span class="portal-chat-history-icon"><i class="bi bi-chat-left-text"></i></span>
                        <span class="portal-chat-history-copy">
                            <strong class="portal-chat-history-title">{{ $item->title ?: 'Percakapan' }}</strong>
                            <small class="portal-chat-history-meta">{{ optional($item->updated_at)->format('d M Y, H:i') }} · {{ $item->messages_count }} pesan</small>
                        </span>
                        <button type="button" class="portal-chat-history-delete" data-conversation-id="{{ $item->id }}" aria-label="Hapus riwayat percakapan" title="Hapus riwayat"><i class="bi bi-trash3"></i></button>
                    </div>
                @empty
                    <div class="portal-chat-history-empty" id="chat-history-empty">
                        <i class="bi bi-chat-square-dots"></i>
                        <span>Belum ada riwayat percakapan. Mulai bertanya dan riwayatnya akan tersimpan di sini.</span>
                    </div>
                @endforelse
            </div>
        </div>

        <details class="portal-chat-topics">
            <summary><i class="bi bi-lightbulb"></i>Contoh topik</summary>
            <ul>
                <li>Bagaimana membaca angka inflasi?</li>
                <li>Apa perbedaan PDRB ADHB dan ADHK?</li>
                <li>Di mana mencari data kemiskinan?</li>
                <li>Bagaimana menentukan indikator penelitian?</li>
            </ul>
        </details>
        <div class="portal-chat-note"><i class="bi bi-info-circle me-1"></i>Gunakan data resmi BPS sebagai rujukan. Untuk kebutuhan yang memerlukan pembahasan mendalam dengan petugas, ajukan <a href="{{ url('konsultasi') }}">konsultasi virtual</a>.</div>
        <div class="portal-chat-note"><i class="bi bi-shield-check me-1"></i>Jangan kirim kata sandi, nomor identitas, atau informasi pribadi sensitif.</div>
    </aside>
</div>

<template id="chat-history-item-template">
    <div class="portal-chat-history-item" role="button" tabindex="0">
        <span class="portal-chat-history-icon"><i class="bi bi-chat-left-text"></i></span>
        <span class="portal-chat-history-copy">
            <strong class="portal-chat-history-title"></strong>
            <small class="portal-chat-history-meta"></small>
        </span>
        <button type="button" class="portal-chat-history-delete" aria-label="Hapus riwayat percakapan" title="Hapus riwayat"><i class="bi bi-trash3"></i></button>
    </div>
</template>
<template id="chat-history-empty-template">
    <div class="portal-chat-history-empty" id="chat-history-empty">
        <i class="bi bi-chat-square-dots"></i>
        <span>Belum ada riwayat percakapan. Mulai bertanya dan riwayatnya akan tersimpan di sini.</span>
    </div>
</template>
@endsection

@section('scripts')
<script src="{{ asset('chat-markdown.js') }}"></script>
<script>
    (() => {
        const form = document.getElementById('portal-chat-form');
        const input = document.getElementById('portal-chat-input');
        const log = document.getElementById('portal-chat-log');
        const send = document.getElementById('portal-chat-send');
        const historyList = document.getElementById('chat-history-list');
        const historyItemTemplate = document.getElementById('chat-history-item-template');
        const historyEmptyTemplate = document.getElementById('chat-history-empty-template');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const greeting = `Halo ${log.dataset.greeting}. Saya siap membantu menjelaskan data dan konsep statistik. Apa yang ingin Anda cari?`;
        let currentConversationId = null;

        const formatTime = (date) => new Intl.DateTimeFormat('id-ID', {
            hour: '2-digit',
            minute: '2-digit'
        }).format(date ? new Date(date) : new Date());
        const timestamp = () => formatTime();
        const addBubble = (text, role = 'assistant', meta = '') => {
            const bubble = document.createElement('div');
            bubble.className = `portal-bubble${role === 'user' ? ' is-user' : ''}`;
            const content = document.createElement('div');
            if (role === 'user') {
                content.textContent = text;
            } else {
                content.className = 'chat-md';
                content.innerHTML = window.renderChatMarkdown(text);
            }
            bubble.append(content);
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

        const resetGreeting = () => {
            log.replaceChildren();
            addBubble(greeting);
        };

        const setActiveHistoryItem = (id) => {
            historyList.querySelectorAll('.portal-chat-history-item').forEach((el) => {
                el.classList.toggle('is-active', Number(el.dataset.conversationId) === Number(id));
            });
        };

        const startNewConversation = () => {
            currentConversationId = null;
            resetGreeting();
            setActiveHistoryItem(null);
            input.focus();
        };

        const removeHistoryEmptyState = () => {
            document.getElementById('chat-history-empty')?.remove();
        };

        const showHistoryEmptyState = () => {
            if (!historyList.querySelector('.portal-chat-history-item') && historyEmptyTemplate) {
                historyList.append(historyEmptyTemplate.content.cloneNode(true));
            }
        };

        const upsertHistoryItem = (id, title) => {
            if (!historyItemTemplate) return;
            removeHistoryEmptyState();
            let item = historyList.querySelector(`.portal-chat-history-item[data-conversation-id="${id}"]`);
            if (!item) {
                item = historyItemTemplate.content.firstElementChild.cloneNode(true);
                item.dataset.conversationId = id;
                item.querySelector('.portal-chat-history-delete').dataset.conversationId = id;
            }
            item.setAttribute('aria-label', `Buka percakapan ${title || 'tanpa judul'}`);
            item.querySelector('.portal-chat-history-title').textContent = title || 'Percakapan';
            item.querySelector('.portal-chat-history-meta').textContent = `${formatTime()} · Baru saja`;
            historyList.prepend(item);
            setActiveHistoryItem(id);
        };

        const loadConversation = async (id) => {
            try {
                const response = await fetch(`/chatbot/conversations/${id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error('Riwayat percakapan tidak dapat dimuat.');
                const data = await response.json();
                currentConversationId = data.id;
                log.replaceChildren();
                if (!data.messages || data.messages.length === 0) {
                    addBubble(greeting);
                } else {
                    data.messages.forEach((item) => {
                        addBubble(item.prompt, 'user', formatTime(item.created_at));
                        addBubble(item.response, 'assistant', `${formatTime(item.created_at)}${item.knowledge_used ? ' · memakai referensi data PST' : ''}`);
                    });
                }
                setActiveHistoryItem(id);
                input.focus();
            } catch (error) {
                alert(error.message || 'Riwayat percakapan tidak dapat dimuat.');
            }
        };

        const deleteConversation = async (id, itemEl) => {
            if (!confirm('Hapus riwayat percakapan ini? Anda juga bisa membiarkannya dan menutup percakapan ini saja.')) return;
            try {
                const response = await fetch(`/chatbot/conversations/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    }
                });
                if (!response.ok) throw new Error('Riwayat percakapan gagal dihapus.');
                itemEl.remove();
                showHistoryEmptyState();
                if (Number(currentConversationId) === Number(id)) startNewConversation();
            } catch (error) {
                alert(error.message || 'Riwayat percakapan gagal dihapus.');
            }
        };

        historyList.addEventListener('click', (event) => {
            const deleteBtn = event.target.closest('.portal-chat-history-delete');
            const item = event.target.closest('.portal-chat-history-item');
            if (deleteBtn) {
                event.stopPropagation();
                deleteConversation(deleteBtn.dataset.conversationId, item);
                return;
            }
            if (item?.dataset.conversationId) loadConversation(item.dataset.conversationId);
        });
        historyList.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const item = event.target.closest('.portal-chat-history-item');
            if (!item?.dataset.conversationId) return;
            event.preventDefault();
            loadConversation(item.dataset.conversationId);
        });
        document.getElementById('new-chat').addEventListener('click', startNewConversation);

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
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({
                        message,
                        conversation_id: currentConversationId
                    })
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Chatbot belum dapat menjawab. Coba kembali sebentar lagi.');
                pending.firstChild.innerHTML = window.renderChatMarkdown(result.reply || 'Maaf, saya belum mendapatkan jawaban untuk pertanyaan tersebut.');
                const details = document.createElement('div');
                details.className = 'portal-bubble-meta';
                details.textContent = `${timestamp()}${result.knowledge_used ? ' · memakai referensi data PST' : ''}`;
                pending.append(details);
                if (result.conversation_id) {
                    currentConversationId = result.conversation_id;
                    upsertHistoryItem(result.conversation_id, result.title);
                }
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
        document.getElementById('clear-chat').addEventListener('click', startNewConversation);
    })();
</script>
@endsection
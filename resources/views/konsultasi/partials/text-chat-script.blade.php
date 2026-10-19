<script>
    (() => {
        const modalElement = document.getElementById('consultationChatModal');
        if (!modalElement || !window.bootstrap?.Modal) return;
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const context = document.getElementById('consultationChatContext');
        const status = document.getElementById('consultationChatStatus');
        const log = document.getElementById('consultationChatLog');
        const form = document.getElementById('consultationChatForm');
        const input = document.getElementById('consultationChatInput');
        const sendButton = document.getElementById('consultationChatSend');
        const compose = form;
        const chatHint = document.getElementById('consultationChatHint');
        const olderButton = document.getElementById('consultationHistoryOlder');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const chatButtons = [...document.querySelectorAll('[data-consultation-chat]')];
        const historyButtons = [...document.querySelectorAll('[data-consultation-history]')];
        let endpoint = '', lastId = 0, pollTimer = null, unreadTimer = null, activeTrigger = null, loading = false, historyMode = false;

        const setUnread = (button, count) => {
            if (!button) return;
            const total = Math.max(0, Number(count) || 0);
            const dot = button.querySelector('.consultation-chat-unread-dot');
            if (dot) dot.hidden = total === 0;
            button.setAttribute('aria-label', total ? 'Chat, ' + total + ' pesan belum dibaca' : 'Buka chat konsultasi');
        };
        const closeTrigger = (button) => {
            if (!button) return;
            button.disabled = true;
            button.title = 'Chat ditutup karena sesi konsultasi sudah selesai atau tidak lagi tersedia.';
            setUnread(button, 0);
        };
        const refreshUnread = async (button) => {
            if (document.hidden || !button.dataset.chatStatusUrl || button.disabled) return;
            try {
                const response = await fetch(button.dataset.chatStatusUrl, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                if (response.status === 404) { closeTrigger(button); return; }
                if (!response.ok) return;
                setUnread(button, (await response.json()).unread_count);
            } catch { /* Keep the last known indicator when offline. */ }
        };
        const appendTextAndLinks = (container, value) => {
            const text = String(value || '');
            const expression = /https?:\/\/[^\s]+/gi;
            let cursor = 0, match;
            while ((match = expression.exec(text)) !== null) {
                container.append(document.createTextNode(text.slice(cursor, match.index)));
                const raw = match[0], urlText = raw.replace(/[.,!?;:]+$/, ''), trailing = raw.slice(urlText.length);
                try {
                    const parsed = new URL(urlText);
                    if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                        const anchor = document.createElement('a');
                        anchor.href = parsed.href; anchor.target = '_blank'; anchor.rel = 'noopener noreferrer'; anchor.textContent = urlText;
                        container.append(anchor);
                    } else container.append(document.createTextNode(urlText));
                } catch { container.append(document.createTextNode(urlText)); }
                container.append(document.createTextNode(trailing));
                cursor = match.index + raw.length;
            }
            container.append(document.createTextNode(text.slice(cursor)));
        };
        const createMessage = (message) => {
            const bubble = document.createElement('div');
            bubble.dataset.messageId = String(message.id);
            bubble.className = 'portal-bubble admin-chat-bubble' + (message.is_mine ? ' is-user' : '');
            const content = document.createElement('div');
            appendTextAndLinks(content, message.body);
            const meta = document.createElement('div');
            meta.className = 'portal-bubble-meta';
            const sender = document.createElement('span');
            sender.textContent = (message.is_mine ? 'Anda' : message.sender_name) + ' - ' + message.sent_at;
            meta.append(sender);
            if (message.is_mine && !historyMode) {
                const receipt = document.createElement('span');
                receipt.className = 'consultation-read-receipt'; receipt.dataset.readReceipt = String(message.id);
                receipt.textContent = message.is_read ? 'Dibaca' : 'Belum dibaca';
                meta.append(receipt);
            }
            bubble.append(content, meta);
            return bubble;
        };
        const appendMessage = (message) => {
            log.append(createMessage(message));
            lastId = Math.max(lastId, Number(message.id));
        };
        const applyReadReceipts = (ids) => {
            const readIds = new Set((ids || []).map(String));
            log.querySelectorAll('[data-read-receipt]').forEach((receipt) => {
                if (readIds.has(receipt.dataset.readReceipt)) receipt.textContent = 'Dibaca';
            });
        };
        const stopChat = () => { if (pollTimer) window.clearInterval(pollTimer); pollTimer = null; };
        const disableChat = (message) => {
            stopChat(); status.textContent = message; closeTrigger(activeTrigger);
            input.disabled = true; sendButton.disabled = true;
        };
        const loadMessages = async (loadOlder = false) => {
            if (!endpoint || loading) return;
            loading = true;
            try {
                const firstMessageId = Number(log.firstElementChild?.dataset.messageId || 0);
                const query = historyMode
                    ? (loadOlder && firstMessageId ? '?before=' + encodeURIComponent(firstMessageId) : '')
                    : '?after=' + encodeURIComponent(lastId);
                const response = await fetch(endpoint + query, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                if (response.status === 404 && !historyMode) { disableChat('Konsultasi sudah selesai atau chat tidak lagi tersedia.'); return; }
                if (!response.ok) throw new Error('Pesan belum dapat dimuat.');
                const result = await response.json();
                const messages = result.messages || [];
                if (historyMode && loadOlder) {
                    const previousHeight = log.scrollHeight;
                    for (const message of [...messages].reverse()) log.insertBefore(createMessage(message), log.firstChild);
                    log.scrollTop += log.scrollHeight - previousHeight;
                } else {
                    for (const message of messages) appendMessage(message);
                }
                olderButton.hidden = !historyMode || !result.has_older;
                if (!historyMode) applyReadReceipts(result.read_message_ids);
                if (activeTrigger) setUnread(activeTrigger, result.unread_count);
                if (messages.length && !loadOlder) log.scrollTop = log.scrollHeight;
                status.textContent = historyMode ? 'Riwayat chat selesai.' : 'Terhubung dengan ' + context.dataset.counterpart + '.';
            } catch (error) { status.textContent = error.message || 'Koneksi chat terputus. Coba lagi.'; }
            finally { loading = false; }
        };
        const resetModal = () => { stopChat(); lastId = 0; log.replaceChildren(); olderButton.hidden = true; };
        chatButtons.forEach((button) => {
            button.addEventListener('click', () => {
                resetModal(); historyMode = false; endpoint = button.dataset.chatUrl; activeTrigger = button;
                compose.hidden = false; chatHint.hidden = false;
                input.value = ''; input.disabled = false; sendButton.disabled = false;
                context.dataset.counterpart = button.dataset.chatPerson || 'peserta konsultasi';
                context.textContent = button.dataset.chatTopic + ' - ' + context.dataset.counterpart;
                status.textContent = 'Memuat pesan...'; modal.show();
            });
        });
        historyButtons.forEach((button) => {
            button.addEventListener('click', () => {
                resetModal(); historyMode = true; endpoint = button.dataset.historyUrl; activeTrigger = null;
                compose.hidden = true; chatHint.hidden = true;
                context.textContent = button.dataset.chatTopic + ' - Riwayat chat';
                status.textContent = 'Memuat riwayat chat...'; modal.show();
            });
        });
        olderButton.addEventListener('click', () => loadMessages(true));
        const refreshAllUnread = () => {
            if (document.hidden) return;
            chatButtons.forEach((button, index) => {
                window.setTimeout(() => refreshUnread(button), index * 250);
            });
        };
        refreshAllUnread();
        if (chatButtons.length) unreadTimer = window.setInterval(refreshAllUnread, 30000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshAllUnread();
        });
        modalElement.addEventListener('shown.bs.modal', () => {
            loadMessages(); if (!historyMode) input.focus();
            if (!historyMode && !pollTimer) pollTimer = window.setInterval(loadMessages, 5000);
        });
        modalElement.addEventListener('hidden.bs.modal', stopChat);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const body = input.value.trim();
            if (!endpoint || !body || sendButton.disabled) return;
            sendButton.disabled = true; status.textContent = 'Mengirim pesan...';
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ body }),
                });
                if (response.status === 404) { disableChat('Konsultasi sudah selesai atau chat tidak lagi tersedia.'); return; }
                if (!response.ok) throw new Error('Pesan gagal dikirim. Coba lagi.');
                input.value = ''; await loadMessages(); input.focus();
            } catch (error) { status.textContent = error.message || 'Pesan gagal dikirim. Coba lagi.'; }
            finally { if (!input.disabled) sendButton.disabled = false; }
        });
        window.addEventListener('beforeunload', () => { stopChat(); if (unreadTimer) window.clearInterval(unreadTimer); });
    })();
</script>

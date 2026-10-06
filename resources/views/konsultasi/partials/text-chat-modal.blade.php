<div class="modal fade portal-modal admin-modal consultation-chat-modal" id="consultationChatModal" tabindex="-1" aria-labelledby="consultationChatTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header consultation-chat-header">
                <div>
                    <span class="portal-eyebrow admin-eyebrow">Konsultasi</span>
                    <h2 class="modal-title" id="consultationChatTitle">Chat konsultasi</h2>
                    <small id="consultationChatContext"></small>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body consultation-chat-body">
                <div class="consultation-chat-status" id="consultationChatStatus" role="status" aria-live="polite">Memuat pesan...</div>
                <div class="portal-chat-log admin-chat-log consultation-chat-log" id="consultationChatLog" role="log" aria-live="polite" aria-relevant="additions"></div>
            </div>
            <form class="portal-chat-compose admin-chat-compose consultation-chat-compose" id="consultationChatForm">
                <textarea class="form-control" id="consultationChatInput" rows="2" maxlength="5000" placeholder="Tulis pesan atau tempel URL..." aria-label="Pesan chat" required></textarea>
                <button class="portal-send admin-btn admin-btn-primary" id="consultationChatSend" type="submit" aria-label="Kirim pesan"><i class="bi bi-send-fill"></i><i class="fe fe-send"></i></button>
            </form>
            <div class="consultation-chat-hint">Chat hanya mendukung teks dan URL. Jangan kirim informasi sensitif.</div>
        </div>
    </div>
</div>
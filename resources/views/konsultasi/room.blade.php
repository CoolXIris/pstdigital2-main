@extends('layout.user-portal')

@section('title', 'Ruang Konsultasi | PST Digital')
@section('topbar_title', 'Ruang konsultasi')

@section('content')
    <section class="portal-heading">
        <div><span class="portal-eyebrow">Sesi terjadwal</span><h1>{{ $meeting->name }}</h1><p>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }} · {{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</p></div>
        <a class="portal-btn portal-btn-outline" href="{{ url('konsultasi') }}"><i class="bi bi-arrow-left"></i> Kembali</a>
    </section>
    <div class="portal-room-layout" id="consultation-room" data-meeting-id="{{ $meeting->id }}" data-room-session="{{ $roomSessionId }}" data-is-requester="{{ $isRequester ? 'true' : 'false' }}" data-signals-url="{{ route('konsultasi.signals.send', $meeting) }}" data-poll-url="{{ route('konsultasi.signals.poll', $meeting) }}" data-return-url="{{ $returnUrl }}" data-ice-servers="{{ json_encode($iceServers) }}">
        <section class="portal-room-stage">
            <video id="remote-video" autoplay playsinline></video>
            <div class="portal-room-placeholder" id="remote-placeholder"><span><i class="bi bi-person-video3"></i></span><strong>Menunggu {{ $isRequester ? 'petugas' : 'pengguna' }} bergabung</strong><small>Saat peserta membuka ruang, koneksi akan tersambung otomatis.</small></div>
            <div class="portal-room-local"><video id="local-video" autoplay playsinline muted></video><span>Anda · {{ $isRequester ? 'Pemohon' : $meeting->assigned_staff }}</span></div>
            <div class="portal-room-caption"><span class="portal-room-live-dot"></span><span id="room-status">Menghubungkan ke ruang...</span><span class="ms-auto">{{ $meeting->assigned_staff }}</span></div>
            <div class="portal-room-controls">
                <button class="portal-room-control" id="toggle-mic" type="button" aria-label="Matikan mikrofon"><i class="bi bi-mic-fill"></i></button>
                <button class="portal-room-control" id="toggle-camera" type="button" aria-label="Matikan kamera"><i class="bi bi-camera-video-fill"></i></button>
                <button class="portal-room-control" id="share-screen" type="button" aria-label="Bagikan layar"><i class="bi bi-display"></i></button>
                <button class="portal-room-control" id="toggle-fullscreen" type="button" aria-label="Layar penuh"><i class="bi bi-fullscreen"></i></button>
                <button class="portal-room-control portal-room-hangup" id="leave-room" type="button" aria-label="Keluar ruang"><i class="bi bi-telephone-x-fill"></i></button>
            </div>
        </section>
        <aside class="portal-room-aside">
            <span class="portal-eyebrow">Detail sesi</span><h2>{{ $meeting->name }}</h2>
            <dl><div><dt>Pemohon</dt><dd>{{ $meeting->user?->name }}</dd></div><div><dt>Petugas</dt><dd>{{ $meeting->assigned_staff }}</dd></div><div><dt>Jadwal</dt><dd>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('d M Y') }}<br>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</dd></div></dl>
            <div class="portal-room-chat-entry">
                <div><strong>Perlu mengirim pesan?</strong><small>Gunakan chat teks tanpa meninggalkan ruang konsultasi.</small></div>
                <button class="portal-btn portal-btn-primary consultation-chat-open" type="button" data-consultation-chat data-chat-url="{{ route('konsultasi.messages', $meeting) }}" data-chat-status-url="{{ route('konsultasi.messages.status', $meeting) }}" data-chat-topic="{{ $meeting->name }}" data-chat-person="{{ $isRequester ? ($meeting->assigned_staff ?: 'Petugas konsultasi') : ($meeting->user?->name ?? 'Pengguna') }}" aria-label="Buka chat konsultasi">
                    <i class="bi bi-chat-dots-fill" aria-hidden="true"></i> Buka chat teks<span class="consultation-chat-unread-dot" hidden aria-hidden="true"></span>
                </button>
            </div>
            <div class="portal-chat-note"><i class="bi bi-shield-check me-1"></i>Media dikirim langsung antarperangkat jika memungkinkan, atau melalui TURN saat jaringan membatasi koneksi. Laravel menangani signaling.</div>
            <div class="portal-room-quality">
                <label for="screen-share-quality"><i class="bi bi-display me-1"></i>Kualitas berbagi layar</label>
                <select id="screen-share-quality" class="form-select" aria-describedby="screen-share-quality-status">
                    <option value="auto" selected>Otomatis · 720p–1080p, 30–60 FPS</option>
                    <option value="720p30">Hemat jaringan · 720p, 30 FPS</option>
                    <option value="1080p30">Tinggi · 1080p, 30 FPS</option>
                    <option value="1080p60">Gerakan halus · 1080p, 60 FPS</option>
                </select>
                <small id="screen-share-quality-status" aria-live="polite">Mode otomatis memilih kualitas sesuai kondisi koneksi saat berbagi layar aktif.</small>
            </div>
            <div class="portal-chat-note" id="turn-configuration-note"><i class="bi bi-info-circle me-1"></i>Izinkan akses kamera dan mikrofon pada browser. Koneksi lintas jaringan memerlukan konfigurasi STUN/TURN pada server hosting.</div>
        </aside>
    </div>
    @include('konsultasi.partials.text-chat-modal')
@endsection

@section('scripts')
<script>
    (() => {
        const room = document.getElementById('consultation-room');
        const localVideo = document.getElementById('local-video');
        const remoteVideo = document.getElementById('remote-video');
        const remotePlaceholder = document.getElementById('remote-placeholder');
        const status = document.getElementById('room-status');
        const requester = room.dataset.isRequester === 'true';
        const roomSession = room.dataset.roomSession;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const iceServers = JSON.parse(room.dataset.iceServers || '[]');
        const turnNote = document.getElementById('turn-configuration-note');
        const hasTurnServer = iceServers.some((server) => {
            const urls = Array.isArray(server.urls) ? server.urls : [server.urls];
            return urls.some((url) => typeof url === 'string' && /^turns?:/i.test(url));
        });
        turnNote.innerHTML = hasTurnServer
            ? '<i class="bi bi-shield-check me-1"></i>Server TURN tersedia sebagai jalur relay jika koneksi langsung terhalang jaringan.'
            : '<i class="bi bi-info-circle me-1"></i>TURN belum dikonfigurasi. Beberapa jaringan dapat memblokir koneksi; hosting perlu menyediakan STUN/TURN agar koneksi lintas jaringan lebih andal.';
        const peer = new RTCPeerConnection({ iceServers, iceCandidatePoolSize: 2, bundlePolicy: 'max-bundle' });
        let videoTransceiver;
        let localStream;
        let screenStream;
        let screenQualityTimer = null;
        let screenQualityBusy = false;
        let selectedScreenQuality = 'auto';
        let automaticScreenProfile = '1080p30';
        let goodNetworkSamples = 0;
        let iceRestartTimer = null;
        let iceRestartAttempts = 0;
        let lastSignal = 0;
        let polling = true;
        let remoteDescriptionReady = false;
        let signalChain = Promise.resolve();
        const pendingCandidates = [];
        const remoteStream = new MediaStream();
        const screenQualitySelect = document.getElementById('screen-share-quality');
        const screenQualityStatus = document.getElementById('screen-share-quality-status');
        const screenProfiles = {
            '720p30': { width: 1280, height: 720, fps: 30, maxBitrate: 1400000, label: '720p · 30 FPS' },
            '1080p30': { width: 1920, height: 1080, fps: 30, maxBitrate: 3000000, label: '1080p · 30 FPS' },
            '1080p60': { width: 1920, height: 1080, fps: 60, maxBitrate: 5500000, label: '1080p · 60 FPS' }
        };

        const updateScreenQualityLabel = () => {
            const profile = screenProfiles[selectedScreenQuality === 'auto' ? automaticScreenProfile : selectedScreenQuality];
            screenQualityStatus.textContent = selectedScreenQuality === 'auto'
                ? `Otomatis${screenStream ? ` · saat ini ${profile.label}` : ' · aktif saat berbagi layar'}`
                : `${profile.label}${screenStream ? ' · kualitas tetap' : ' · diterapkan saat berbagi layar'}`;
        };

        const applyScreenQuality = async () => {
            const activeStream = screenStream;
            const track = activeStream?.getVideoTracks()[0];
            if (!track || !videoTransceiver?.sender) return;

            const profile = screenProfiles[selectedScreenQuality === 'auto' ? automaticScreenProfile : selectedScreenQuality];
            try {
                await track.applyConstraints({
                    width: { max: profile.width },
                    height: { max: profile.height },
                    frameRate: { ideal: profile.fps, max: profile.fps }
                });
            } catch {
                // Some browsers do not support applying every display-capture constraint.
                // Sender limits below still cap the encoded stream where supported.
            }
            if (screenStream !== activeStream) return;

            try {
                const parameters = videoTransceiver.sender.getParameters();
                parameters.encodings ??= [{}];
                if (parameters.encodings?.length) {
                    parameters.encodings[0].maxBitrate = profile.maxBitrate;
                    parameters.encodings[0].maxFramerate = profile.fps;
                    const settings = track.getSettings();
                    const sourceWidth = settings.width || profile.width;
                    const sourceHeight = settings.height || profile.height;
                    parameters.encodings[0].scaleResolutionDownBy = Math.max(
                        1,
                        sourceWidth / profile.width,
                        sourceHeight / profile.height
                    );
                    await videoTransceiver.sender.setParameters(parameters);
                }
            } catch {
                // Capture constraints remain in place if an encoder parameter is unsupported.
            }
            updateScreenQualityLabel();
        };

        const adaptScreenQuality = async () => {
            if (!screenStream || selectedScreenQuality !== 'auto' || screenQualityBusy) return;
            screenQualityBusy = true;
            try {
                const reports = await peer.getStats();
                const stats = Array.from(reports.values());
                const selectedPairId = stats.find((item) => item.type === 'transport' && item.selectedCandidatePairId)
                    ?.selectedCandidatePairId;
                const candidatePair = stats.find((item) => item.type === 'candidate-pair' && item.id === selectedPairId)
                    || stats.find((item) => item.type === 'candidate-pair'
                        && item.state === 'succeeded'
                        && (item.selected || item.nominated))
                    || stats.find((item) => item.type === 'candidate-pair' && item.selected);
                const outboundVideo = stats.find((item) => item.type === 'outbound-rtp'
                    && (item.kind === 'video' || item.mediaType === 'video')
                    && !item.isRemote);
                const remoteInbound = outboundVideo
                    ? stats.find((item) => item.type === 'remote-inbound-rtp'
                        && (item.id === outboundVideo.remoteId || item.localId === outboundVideo.id))
                    : null;
                const availableBitrate = Number(candidatePair?.availableOutgoingBitrate);
                const roundTripTime = Number(candidatePair?.currentRoundTripTime ?? remoteInbound?.roundTripTime);
                const packetLoss = Number(remoteInbound?.fractionLost);
                const bandwidthLimited = outboundVideo?.qualityLimitationReason === 'bandwidth';
                const cpuLimited = outboundVideo?.qualityLimitationReason === 'cpu';
                const poorNetwork = bandwidthLimited
                    || cpuLimited
                    || (Number.isFinite(packetLoss) && packetLoss > 0.06)
                    || (Number.isFinite(roundTripTime) && roundTripTime > 0.65)
                    || (Number.isFinite(availableBitrate) && availableBitrate < 1800000);

                let nextProfile = automaticScreenProfile;
                if (poorNetwork) {
                    nextProfile = '720p30';
                    goodNetworkSamples = 0;
                } else if (Number.isFinite(availableBitrate) && availableBitrate >= 5500000
                    && (!Number.isFinite(packetLoss) || packetLoss < 0.02)
                    && (!Number.isFinite(roundTripTime) || roundTripTime < 0.4)) {
                    goodNetworkSamples += 1;
                    if (goodNetworkSamples >= 2) nextProfile = '1080p60';
                } else if (Number.isFinite(availableBitrate)) {
                    goodNetworkSamples = 0;
                    nextProfile = '1080p30';
                }

                if (nextProfile !== automaticScreenProfile) {
                    automaticScreenProfile = nextProfile;
                    await applyScreenQuality();
                }
            } catch {
                // Keep the current bounded profile if network statistics are unavailable.
            } finally {
                screenQualityBusy = false;
            }
        };

        screenQualitySelect.addEventListener('change', async () => {
            selectedScreenQuality = screenQualitySelect.value;
            goodNetworkSamples = 0;
            if (selectedScreenQuality === 'auto') automaticScreenProfile = '1080p30';
            updateScreenQualityLabel();
            await applyScreenQuality();
        });

        const scheduleIceRestart = (delay) => {
            if (!requester || iceRestartTimer || iceRestartAttempts >= 3) return;
            iceRestartTimer = window.setTimeout(async () => {
                iceRestartTimer = null;
                if (peer.iceConnectionState === 'connected' || peer.iceConnectionState === 'completed') return;
                if (peer.signalingState !== 'stable') {
                    scheduleIceRestart(1500);
                    return;
                }
                iceRestartAttempts += 1;
                try {
                    status.textContent = 'Koneksi terganggu; mencoba menyambungkan kembali...';
                    await peer.setLocalDescription(await peer.createOffer({ iceRestart: true }));
                    await sendSignal('description', peer.localDescription.toJSON());
                } catch {
                    status.textContent = 'Koneksi belum pulih. Periksa jaringan dan konfigurasi TURN.';
                }
            }, delay);
        };

        const refreshRemotePlayback = () => {
            const tracks = peer.getReceivers()
                .map((receiver) => receiver.track)
                .filter((track) => track && track.readyState === 'live');
            if (!tracks.some((track) => track.kind === 'video')) return;
            remoteVideo.pause();
            remoteVideo.srcObject = null;
            window.requestAnimationFrame(() => {
                remoteVideo.srcObject = new MediaStream(tracks);
                remoteVideo.play().catch(() => {});
            });
        };

        const sendSignal = async (type, payload) => {
            const response = await fetch(room.dataset.signalsUrl, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ type, payload, session_id: roomSession })
            });
            if (!response.ok) throw new Error('Gagal mengirim data koneksi.');
        };

        peer.ontrack = (event) => {
            if (event.streams?.[0]) {
                remoteVideo.srcObject = event.streams[0];
            } else if (!remoteStream.getTracks().some((track) => track.id === event.track.id)) {
                remoteStream.addTrack(event.track);
                remoteVideo.srcObject = remoteStream;
            }
            remotePlaceholder.hidden = true;
            status.textContent = 'Terhubung';
        };
        peer.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal('candidate', event.candidate.toJSON()).catch((error) => {
                    status.textContent = error.message || 'Gagal mengirim data koneksi.';
                });
            }
        };
        peer.onconnectionstatechange = () => {
            if (peer.connectionState === 'connected') status.textContent = 'Terhubung';
            if (peer.connectionState === 'disconnected' || peer.connectionState === 'failed') status.textContent = 'Koneksi terganggu; mencoba menyambungkan kembali...';
        };
        peer.oniceconnectionstatechange = () => {
            const state = peer.iceConnectionState;
            if (state === 'connected' || state === 'completed') {
                iceRestartAttempts = 0;
                if (iceRestartTimer) window.clearTimeout(iceRestartTimer);
                iceRestartTimer = null;
                status.textContent = 'Terhubung';
            } else if (state === 'failed') {
                if (iceRestartAttempts >= 3) {
                    status.textContent = 'Koneksi belum pulih. Periksa jaringan atau minta administrator memeriksa TURN.';
                } else {
                    status.textContent = 'Koneksi gagal; mencoba jalur koneksi ulang...';
                    scheduleIceRestart(1000);
                }
            } else if (state === 'disconnected') {
                if (iceRestartAttempts >= 3) {
                    status.textContent = 'Koneksi belum pulih. Periksa jaringan atau minta administrator memeriksa TURN.';
                } else {
                    status.textContent = 'Koneksi tidak stabil; mencoba menyambungkan kembali...';
                    scheduleIceRestart(6000);
                }
            }
        };

        const consumeSignals = async () => {
            let pollDelay = 5000;
            while (polling) {
                try {
                    const response = await fetch(`${room.dataset.pollUrl}?after=${lastSignal}&session_id=${encodeURIComponent(roomSession)}`, { headers: { 'Accept': 'application/json' } });
                    if (response.status === 409) {
                        polling = false;
                        status.textContent = 'Ruang diperbarui. Bergabung kembali...';
                        remotePlaceholder.hidden = false;
                        remotePlaceholder.querySelector('strong').textContent = 'Menghubungkan kembali';
                        window.setTimeout(() => window.location.reload(), 500);
                        break;
                    }
                    if (!response.ok) {
                        polling = false;
                        status.textContent = 'Sesi konsultasi telah berakhir.';
                        remotePlaceholder.hidden = false;
                        remotePlaceholder.querySelector('strong').textContent = 'Sesi telah ditutup';
                        break;
                    }
                    const { signals, peer_present: peerPresent } = await response.json();
                    if (peer.connectionState === 'connected') pollDelay = 3500;
                    else if (signals.length) pollDelay = 1200;
                    else if (peerPresent) pollDelay = 2500;
                    else pollDelay = 5000;
                    if (document.hidden) pollDelay = Math.max(pollDelay, 5000);
                    if (!peerPresent && peer.connectionState !== 'closed') {
                        status.textContent = requester ? 'Menunggu petugas bergabung' : 'Menunggu pengguna bergabung';
                        remotePlaceholder.hidden = false;
                        remoteVideo.srcObject = null;
                    }
                    for (const signal of signals) {
                        lastSignal = Math.max(lastSignal, Number(signal.id));
                        signalChain = signalChain.then(async () => {
                            if (signal.type === 'description') {
                                if (peer.remoteDescription?.type === signal.payload.type && peer.remoteDescription?.sdp === signal.payload.sdp) return;
                                if (signal.payload.type === 'offer' && peer.signalingState !== 'stable') return;
                                if (signal.payload.type === 'answer' && peer.signalingState !== 'have-local-offer') return;
                                await peer.setRemoteDescription(signal.payload);
                                remoteDescriptionReady = true;
                                while (pendingCandidates.length) {
                                    await peer.addIceCandidate(pendingCandidates.shift());
                                }
                                if (signal.payload.type === 'offer' && peer.signalingState === 'have-remote-offer') {
                                    await peer.setLocalDescription(await peer.createAnswer());
                                    await sendSignal('description', peer.localDescription.toJSON());
                                }
                            } else if (signal.type === 'media-state') {
                                refreshRemotePlayback();
                            } else if (signal.type === 'candidate' && signal.payload?.candidate) {
                                if (!remoteDescriptionReady) pendingCandidates.push(signal.payload);
                                else await peer.addIceCandidate(signal.payload);
                            }
                        }).catch((error) => { status.textContent = `Koneksi gagal: ${error.message}`; });
                    }
                } catch (error) {
                    status.textContent = error.message;
                    if (error.message.includes('jadwal telah berakhir')) polling = false;
                }
                await new Promise((resolve) => setTimeout(resolve, pollDelay));
            }
        };

        const startRoom = async () => {
            if (!navigator.mediaDevices?.getUserMedia || !window.RTCPeerConnection) {
                status.textContent = 'Browser ini tidak mendukung WebRTC.';
                return;
            }
            if (!window.isSecureContext) {
                status.textContent = 'Ruang meeting memerlukan HTTPS agar kamera, mikrofon, dan screen share dapat digunakan.';
                return;
            }
            const mediaParts = await Promise.allSettled([
                navigator.mediaDevices.getUserMedia({ audio: true, video: false }),
                navigator.mediaDevices.getUserMedia({ audio: false, video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 24, max: 30 } } })
            ]);
            const tracks = mediaParts.flatMap((result) => result.status === 'fulfilled' ? result.value.getTracks() : []);
            localStream = new MediaStream(tracks);
            localVideo.srcObject = localStream;
            const audioTrack = localStream.getAudioTracks()[0] || null;
            const videoTrack = localStream.getVideoTracks()[0] || null;
            if (audioTrack) {
                peer.addTrack(audioTrack, localStream);
            } else {
                peer.addTransceiver('audio', { direction: 'recvonly' });
            }
            if (videoTrack) {
                const videoSender = peer.addTrack(videoTrack, localStream);
                videoTransceiver = peer.getTransceivers().find((transceiver) => transceiver.sender === videoSender);
            } else {
                videoTransceiver = peer.addTransceiver('video', { direction: 'recvonly' });
            }
            consumeSignals();
            if (!audioTrack && !videoTrack) status.textContent = 'Kamera/mikrofon tidak tersedia; bergabung dalam mode dengar.';
            else status.textContent = requester ? 'Menunggu petugas bergabung' : 'Menghubungkan dengan pemohon';
            if (requester) {
                await peer.setLocalDescription(await peer.createOffer());
                await sendSignal('description', peer.localDescription.toJSON());
            }
        };

        document.getElementById('toggle-mic').addEventListener('click', (event) => {
            const track = localStream?.getAudioTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            event.currentTarget.classList.toggle('is-muted', !track.enabled);
            event.currentTarget.setAttribute('aria-label', track.enabled ? 'Matikan mikrofon' : 'Nyalakan mikrofon');
        });
        document.getElementById('toggle-camera').addEventListener('click', (event) => {
            const track = localStream?.getVideoTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            event.currentTarget.classList.toggle('is-muted', !track.enabled);
            event.currentTarget.setAttribute('aria-label', track.enabled ? 'Matikan kamera' : 'Nyalakan kamera');
        });
        const stopScreenShare = async () => {
            const activeScreen = screenStream;
            if (!activeScreen) return;
            screenStream = null;
            if (screenQualityTimer) window.clearInterval(screenQualityTimer);
            screenQualityTimer = null;
            activeScreen.getTracks().forEach((track) => track.stop());
            const cameraTrack = localStream?.getVideoTracks()[0] || null;
            await videoTransceiver.sender.replaceTrack(cameraTrack);
            try {
                const parameters = videoTransceiver.sender.getParameters();
                if (parameters.encodings?.length) {
                    delete parameters.encodings[0].maxBitrate;
                    delete parameters.encodings[0].maxFramerate;
                    delete parameters.encodings[0].scaleResolutionDownBy;
                    await videoTransceiver.sender.setParameters(parameters);
                }
            } catch {
                // Restoring the camera track still succeeds when encoder parameters are immutable.
            }
            sendSignal('media-state', { source: 'camera' }).catch(() => {});
            localVideo.srcObject = localStream || null;
            const shareButton = document.getElementById('share-screen');
            shareButton.classList.remove('is-muted');
            shareButton.setAttribute('aria-label', 'Bagikan layar');
            updateScreenQualityLabel();
        };
        document.getElementById('share-screen').addEventListener('click', async (event) => {
            const shareButton = event.currentTarget;
            if (screenStream) {
                await stopScreenShare();
                return;
            }
            try {
                if (!localStream?.getVideoTracks().length) {
                    status.textContent = 'Aktifkan kamera sebelum membagikan layar.';
                    return;
                }
                try {
                    screenStream = await navigator.mediaDevices.getDisplayMedia({
                        video: {
                            width: { ideal: 1920, max: 1920 },
                            height: { ideal: 1080, max: 1080 },
                            frameRate: { ideal: 30, max: 60 }
                        },
                        audio: false
                    });
                } catch (captureError) {
                    if (captureError.name !== 'OverconstrainedError') throw captureError;
                    screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                }
                const screenTrack = screenStream.getVideoTracks()[0];
                await videoTransceiver.sender.replaceTrack(screenTrack);
                if (selectedScreenQuality === 'auto') automaticScreenProfile = '1080p30';
                await applyScreenQuality();
                screenQualityTimer = window.setInterval(adaptScreenQuality, 5000);
                sendSignal('media-state', { source: 'screen' }).catch(() => {});
                localVideo.srcObject = screenStream;
                shareButton.classList.add('is-muted');
                shareButton.setAttribute('aria-label', 'Hentikan berbagi layar');
                updateScreenQualityLabel();
                screenTrack.addEventListener('ended', () => {
                    stopScreenShare().catch((error) => {
                        status.textContent = 'Gagal kembali ke kamera: ' + error.message;
                    });
                }, { once: true });
            } catch (error) {
                screenStream?.getTracks().forEach((track) => track.stop());
                screenStream = null;
                status.textContent = error.name === 'NotAllowedError' ? 'Izin berbagi layar dibatalkan.' : 'Berbagi layar tidak tersedia.';
            }
        });
        document.getElementById('toggle-fullscreen').addEventListener('click', async () => {
            try {
                if (document.fullscreenElement) await document.exitFullscreen();
                else await document.querySelector('.portal-room-stage').requestFullscreen();
            } catch {
                status.textContent = 'Mode layar penuh tidak tersedia pada browser ini.';
            }
        });
        document.getElementById('leave-room').addEventListener('click', async () => {
            polling = false;
            if (screenQualityTimer) window.clearInterval(screenQualityTimer);
            if (iceRestartTimer) window.clearTimeout(iceRestartTimer);
            await sendSignal('leave', null).catch(() => {});
            localStream?.getTracks().forEach((track) => track.stop());
            screenStream?.getTracks().forEach((track) => track.stop());
            peer.close();
            window.location.assign(room.dataset.returnUrl);
        });
        window.addEventListener('beforeunload', () => {
            localStream?.getTracks().forEach((track) => track.stop());
            screenStream?.getTracks().forEach((track) => track.stop());
            if (screenQualityTimer) window.clearInterval(screenQualityTimer);
            if (iceRestartTimer) window.clearTimeout(iceRestartTimer);
            peer.close();
        });
        updateScreenQualityLabel();
        startRoom().catch((error) => { status.textContent = `Gagal bergabung: ${error.message}`; });
    })();
</script>
@include('konsultasi.partials.text-chat-script')
@endsection

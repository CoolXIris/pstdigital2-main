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
            <div class="portal-chat-note"><i class="bi bi-shield-check me-1"></i>Koneksi audio/video dibuat antarperangkat. Server aplikasi hanya meneruskan metadata signaling.</div>
            <div class="portal-chat-note"><i class="bi bi-info-circle me-1"></i>Izinkan akses kamera dan mikrofon pada browser. Jika koneksi antarjaringan gagal, administrator server perlu mengonfigurasi TURN milik BPS.</div>
        </aside>
    </div>
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
        const peer = new RTCPeerConnection({ iceServers: JSON.parse(room.dataset.iceServers || '[]') });
        let videoTransceiver;
        let localStream;
        let screenStream;
        let lastSignal = 0;
        let polling = true;
        let remoteDescriptionReady = false;
        let signalChain = Promise.resolve();
        const pendingCandidates = [];
        const remoteStream = new MediaStream();

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
            if (peer.connectionState === 'disconnected' || peer.connectionState === 'failed') status.textContent = 'Koneksi terputus';
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
                                if (signal.payload.type === 'offer' && !peer.localDescription) {
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
            activeScreen.getTracks().forEach((track) => track.stop());
            const cameraTrack = localStream?.getVideoTracks()[0] || null;
            await videoTransceiver.sender.replaceTrack(cameraTrack);
            sendSignal('media-state', { source: 'camera' }).catch(() => {});
            localVideo.srcObject = localStream || null;
            const shareButton = document.getElementById('share-screen');
            shareButton.classList.remove('is-muted');
            shareButton.setAttribute('aria-label', 'Bagikan layar');
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
                screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                const screenTrack = screenStream.getVideoTracks()[0];
                await videoTransceiver.sender.replaceTrack(screenTrack);
                sendSignal('media-state', { source: 'screen' }).catch(() => {});
                localVideo.srcObject = screenStream;
                shareButton.classList.add('is-muted');
                shareButton.setAttribute('aria-label', 'Hentikan berbagi layar');
                screenTrack.addEventListener('ended', () => {
                    stopScreenShare().catch((error) => {
                        status.textContent = 'Gagal kembali ke kamera: ' + error.message;
                    });
                }, { once: true });
            } catch (error) {
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
            await sendSignal('leave', null).catch(() => {});
            localStream?.getTracks().forEach((track) => track.stop());
            screenStream?.getTracks().forEach((track) => track.stop());
            peer.close();
            window.location.assign(room.dataset.returnUrl);
        });
        window.addEventListener('beforeunload', () => {
            localStream?.getTracks().forEach((track) => track.stop());
            screenStream?.getTracks().forEach((track) => track.stop());
            peer.close();
        });
        startRoom().catch((error) => { status.textContent = `Gagal bergabung: ${error.message}`; });
    })();
</script>
@endsection

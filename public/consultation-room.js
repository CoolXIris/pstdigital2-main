(() => {
    const room = document.getElementById('consultation-room');
    if (!room) return;

    const gallery = document.getElementById('room-gallery');
    const cameraStrip = document.getElementById('room-camera-strip');
    const screenStage = document.getElementById('room-screen-stage');
    const screenVideo = document.getElementById('room-screen-video');
    const screenLabel = document.getElementById('room-screen-label');
    const status = document.getElementById('room-status');
    const countLabel = document.getElementById('room-participant-count');
    const placeholder = document.getElementById('remote-placeholder');
    const localVideo = document.createElement('video');
    const requester = room.dataset.isRequester === 'true';
    const currentUserId = Number(room.dataset.currentUserId);
    const roomSession = room.dataset.roomSession;
    let roomCapacity = Number(room.dataset.roomCapacity || 2);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const iceServers = JSON.parse(room.dataset.iceServers || '[]');
    const turnNote = document.getElementById('turn-configuration-note');
    const hasTurnServer = iceServers.some((server) => {
        const urls = Array.isArray(server.urls) ? server.urls : [server.urls];
        return urls.some((url) => typeof url === 'string' && /^turns?:/i.test(url));
    });
    turnNote.innerHTML = hasTurnServer
        ? '<i class="bi bi-shield-check me-1"></i>Server TURN tersedia sebagai jalur relay jika koneksi langsung terhalang jaringan.'
        : '<i class="bi bi-info-circle me-1"></i>TURN belum dikonfigurasi. Beberapa jaringan dapat memblokir koneksi; koneksi lintas jaringan mungkin terbatas.';

    const localTile = document.createElement('article');
    localTile.className = 'portal-room-tile is-local';
    localTile.dataset.participantId = String(currentUserId);
    localVideo.autoplay = true;
    localVideo.playsInline = true;
    localVideo.muted = true;
    localTile.append(localVideo);
    const localLabel = document.createElement('span');
    localLabel.textContent = requester ? 'Anda · Pemohon' : 'Anda · Petugas';
    localTile.append(localLabel);
    cameraStrip.insertBefore(localTile, placeholder);

    let localStream;
    let screenStream;
    let screenQualityTimer = null;
    let screenQualityBusy = false;
    let selectedScreenQuality = 'auto';
    let automaticScreenProfile = '1080p30';
    let goodNetworkSamples = 0;
    let lastSignal = Number(room.dataset.lastSignalId || 0);
    let polling = true;
    const peers = new Map();
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
        const track = screenStream?.getVideoTracks()[0];
        if (!track) return;
        const profile = screenProfiles[selectedScreenQuality === 'auto' ? automaticScreenProfile : selectedScreenQuality];
        if (screenStream) {
            try {
                await track.applyConstraints({
                    width: { max: profile.width },
                    height: { max: profile.height },
                    frameRate: { ideal: profile.fps, max: profile.fps }
                });
            } catch {
                // Sender limits still cap the stream where capture constraints are unsupported.
            }
        }

        for (const peer of peers.values()) {
            const sender = peer.screenSender;
            if (!sender || sender.track?.kind !== 'video') continue;
            try {
                const parameters = sender.getParameters();
                parameters.encodings ??= [{}];
                if (!parameters.encodings.length) continue;
                parameters.encodings[0].maxBitrate = profile.maxBitrate;
                parameters.encodings[0].maxFramerate = profile.fps;
                const settings = track.getSettings();
                parameters.encodings[0].scaleResolutionDownBy = Math.max(
                    1,
                    (settings.width || profile.width) / profile.width,
                    (settings.height || profile.height) / profile.height
                );
                await sender.setParameters(parameters);
            } catch {
                // The selected capture profile remains active if encoder limits are unsupported.
            }
        }
        updateScreenQualityLabel();
    };

    const adaptScreenQuality = async () => {
        if (!screenStream || selectedScreenQuality !== 'auto' || screenQualityBusy) return;
        screenQualityBusy = true;
        try {
            const peer = [...peers.values()].find((item) => item.connection.connectionState === 'connected');
            if (!peer) return;
            const stats = Array.from((await peer.connection.getStats()).values());
            const selectedPairId = stats.find((item) => item.type === 'transport' && item.selectedCandidatePairId)?.selectedCandidatePairId;
            const candidatePair = stats.find((item) => item.type === 'candidate-pair' && item.id === selectedPairId)
                || stats.find((item) => item.type === 'candidate-pair' && item.state === 'succeeded' && (item.selected || item.nominated));
            const outboundVideo = stats.find((item) => item.type === 'outbound-rtp' && (item.kind === 'video' || item.mediaType === 'video') && !item.isRemote);
            const remoteInbound = outboundVideo
                ? stats.find((item) => item.type === 'remote-inbound-rtp' && (item.id === outboundVideo.remoteId || item.localId === outboundVideo.id))
                : null;
            const availableBitrate = Number(candidatePair?.availableOutgoingBitrate);
            const roundTripTime = Number(candidatePair?.currentRoundTripTime ?? remoteInbound?.roundTripTime);
            const packetLoss = Number(remoteInbound?.fractionLost);
            const poorNetwork = outboundVideo?.qualityLimitationReason === 'bandwidth'
                || outboundVideo?.qualityLimitationReason === 'cpu'
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
                if (++goodNetworkSamples >= 2) nextProfile = '1080p60';
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

    const sendSignal = async (type, payload, recipientUserId = null) => {
        const response = await fetch(room.dataset.signalsUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ type, payload, session_id: roomSession, recipient_user_id: recipientUserId })
        });
        if (!response.ok) {
            const result = await response.json().catch(() => ({}));
            throw new Error(result.message || 'Gagal mengirim data koneksi.');
        }
    };

    const sendMediaState = (payload) => Promise.all([...peers.keys()].map(async (id) => {
        try {
            await sendSignal('media-state', payload, id);
        } catch (error) {
            status.textContent = `Status media tidak tersinkron ke peserta: ${error.message}`;
        }
    }));

    const createTile = (participant) => {
        const tile = document.createElement('article');
        tile.className = 'portal-room-tile';
        tile.dataset.participantId = String(participant.id);
        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        tile.append(video);
        const label = document.createElement('span');
        label.textContent = `${participant.name}${participant.is_requester ? ' · Pemohon' : ' · Petugas'}`;
        tile.append(label);
        cameraStrip.insertBefore(tile, placeholder);
        return { tile, video };
    };

    const updateScreenLayout = () => {
        const activeSharer = [...peers.values()].find((peer) => peer.screenStream);
        const localSharing = Boolean(screenStream);
        const sharing = localSharing || Boolean(activeSharer);
        gallery.classList.toggle('has-screen-share', sharing);
        screenStage.hidden = !sharing;
        if (localSharing) {
            screenVideo.srcObject = screenStream;
            screenLabel.textContent = 'Berbagi layar · Anda';
        } else if (activeSharer) {
            screenVideo.srcObject = activeSharer.screenStream;
            screenLabel.textContent = `Berbagi layar · ${activeSharer.name}`;
        } else {
            screenVideo.srcObject = null;
            screenLabel.textContent = '';
        }
    };

    const setRoomStatus = () => {
        const connectedCount = [...peers.values()].filter((peer) => peer.connection.connectionState === 'connected').length;
        const participantCount = peers.size + 1;
        gallery.dataset.count = String(participantCount);
        countLabel.textContent = `${participantCount}/${roomCapacity} peserta`;
        placeholder.hidden = participantCount > 1;
        updateScreenLayout();
        if (connectedCount > 0) status.textContent = `${connectedCount + 1} peserta terhubung`;
        else status.textContent = participantCount > 1 ? 'Menghubungkan peserta...' : 'Menunggu peserta lain bergabung';
    };

    const enqueuePeerOperation = (peer, operation) => {
        peer.operationChain = peer.operationChain.then(async () => {
            if (peers.get(peer.id) !== peer || peer.connection.signalingState === 'closed') return;
            await operation();
        }).catch((error) => {
            status.textContent = `Koneksi gagal: ${error.message}`;
        });
        return peer.operationChain;
    };

    const negotiatePeer = async (peer) => {
        const connection = peer.connection;
        if (!peer.negotiationPending || peer.makingOffer || peer.answeringRemoteOffer
            || (!peer.initialized && !peer.initialOfferer)
            || connection.signalingState !== 'stable') return;

        peer.makingOffer = true;
        peer.negotiationPending = false;
        try {
            await connection.setLocalDescription(await connection.createOffer());
            await sendSignal('description', connection.localDescription.toJSON(), peer.id);
        } catch (error) {
            status.textContent = `Gagal menegosiasikan media: ${error.message}`;
        } finally {
            peer.makingOffer = false;
            if (peer.negotiationPending && connection.signalingState === 'stable') {
                window.setTimeout(() => requestNegotiation(peer), 0);
            }
        }
    };

    const requestNegotiation = (peer) => {
        peer.negotiationPending = true;
        if (!peer.initialized && !peer.initialOfferer) return Promise.resolve();
        return enqueuePeerOperation(peer, () => negotiatePeer(peer));
    };

    const createPeer = (participant) => {
        const id = Number(participant.id);
        const connection = new RTCPeerConnection({ iceServers, iceCandidatePoolSize: 2, bundlePolicy: 'max-bundle' });
        const { tile, video } = createTile(participant);
        const remoteStream = new MediaStream();
        const audioTrack = localStream?.getAudioTracks()[0];
        const videoTrack = localStream?.getVideoTracks()[0] || null;
        let audioSender = null;
        let videoSender = null;
        if (audioTrack) audioSender = connection.addTrack(audioTrack, localStream);
        else connection.addTransceiver('audio', { direction: 'recvonly' });
        if (videoTrack) videoSender = connection.addTrack(videoTrack, localStream);
        else videoSender = connection.addTransceiver('video', { direction: 'sendrecv' }).sender;
        const activeScreenTrack = screenStream?.getVideoTracks()[0] || null;
        const screenSender = activeScreenTrack ? connection.addTrack(activeScreenTrack, screenStream) : null;

        const peer = {
            id,
            name: participant.name,
            connection,
            tile,
            video,
            audioSender,
            videoSender,
            screenStream: null,
            screenSender,
            screenStreamId: null,
            screenTrackId: null,
            receivedVideoStreams: new Map(),
            cameraStream: null,
            initialized: false,
            initialOfferer: currentUserId < id,
            makingOffer: false,
            polite: currentUserId > id,
            ignoreOffer: false,
            settingRemoteAnswer: false,
            answeringRemoteOffer: false,
            negotiationPending: Boolean(screenSender),
            remoteDescriptionReady: false,
            pendingCandidates: [],
            operationChain: Promise.resolve(),
            iceRestarts: 0
        };
        peers.set(id, peer);
        connection.onnegotiationneeded = () => {
            if (!peer.initialized && !peer.screenSender) return;
            requestNegotiation(peer);
        };
        connection.onsignalingstatechange = () => {
            if (connection.signalingState === 'stable' && peer.negotiationPending && !peer.answeringRemoteOffer) {
                requestNegotiation(peer);
            }
        };
        connection.ontrack = (event) => {
            const incomingStream = event.streams?.[0];
            if (incomingStream) peer.receivedVideoStreams.set(event.track.id, incomingStream);
            if (incomingStream && (incomingStream.id === peer.screenStreamId || event.track.id === peer.screenTrackId)) {
                peer.screenStream = incomingStream;
                peer.cameraStream = [...peer.receivedVideoStreams.entries()]
                    .find(([trackId]) => trackId !== peer.screenTrackId)?.[1] || null;
            } else if (event.track.id === peer.screenTrackId) {
                peer.screenStream = new MediaStream([event.track]);
            } else if (event.track.kind === 'video') {
                peer.cameraStream = incomingStream || new MediaStream([event.track]);
                video.srcObject = peer.cameraStream;
            } else if (!remoteStream.getTracks().some((track) => track.id === event.track.id)) {
                remoteStream.addTrack(event.track);
                if (!video.srcObject) video.srcObject = remoteStream;
            }
            video.play().catch(() => {});
            updateScreenLayout();
        };
        connection.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal('candidate', event.candidate.toJSON(), id).catch((error) => { status.textContent = error.message; });
            }
        };
        connection.onconnectionstatechange = () => {
            if (connection.connectionState === 'failed' && currentUserId < id && peer.iceRestarts < 2) {
                peer.iceRestarts += 1;
                connection.restartIce();
                requestNegotiation(peer);
            }
            setRoomStatus();
        };
        setRoomStatus();
        if (screenSender) {
            sendSignal('media-state', {
                source: 'screen',
                stream_id: screenStream.id,
                track_id: activeScreenTrack.id
            }, id).catch((error) => { status.textContent = error.message; });
        }
        if (peer.initialOfferer) {
            requestNegotiation(peer);
        }
        return peer;
    };

    const reconcileParticipants = (participants) => {
        const currentIds = new Set(participants.map((participant) => Number(participant.id)));
        for (const participant of participants) {
            const id = Number(participant.id);
            if (id !== currentUserId && !peers.has(id)) createPeer(participant);
        }
        for (const [id, peer] of peers) {
            if (currentIds.has(id)) continue;
            peer.connection.close();
            peer.tile.remove();
            peers.delete(id);
        }
        setRoomStatus();
    };

    const handleSignal = async (signal) => {
        const peer = peers.get(Number(signal.sender_user_id));
        if (!peer || Number(signal.recipient_user_id) !== currentUserId) return;
        if (signal.type === 'leave') {
            peer.connection.close();
            peer.tile.remove();
            peers.delete(peer.id);
            setRoomStatus();
            return;
        }
        const connection = peer.connection;
        if (signal.type === 'description') {
            if (connection.remoteDescription?.type === signal.payload.type
                && connection.remoteDescription?.sdp === signal.payload.sdp) return;
            const readyForOffer = !peer.makingOffer
                && (connection.signalingState === 'stable' || peer.settingRemoteAnswer);
            const offerCollision = signal.payload.type === 'offer' && !readyForOffer;
            if (signal.payload.type === 'answer' && connection.signalingState !== 'have-local-offer') return;
            peer.ignoreOffer = !peer.polite && offerCollision;
            if (peer.ignoreOffer) return;
            if (offerCollision) peer.negotiationPending = true;
            peer.settingRemoteAnswer = signal.payload.type === 'answer';
            try {
                if (offerCollision) await connection.setLocalDescription({ type: 'rollback' });
                await connection.setRemoteDescription(signal.payload);
            } finally {
                peer.settingRemoteAnswer = false;
            }
            peer.ignoreOffer = false;
            peer.remoteDescriptionReady = true;
            peer.initialized = true;
            while (peer.pendingCandidates.length) await connection.addIceCandidate(peer.pendingCandidates.shift());
            if (signal.payload.type === 'offer' && connection.signalingState === 'have-remote-offer') {
                peer.answeringRemoteOffer = true;
                try {
                    await connection.setLocalDescription(await connection.createAnswer());
                    await sendSignal('description', connection.localDescription.toJSON(), peer.id);
                } finally {
                    peer.answeringRemoteOffer = false;
                }
            }
            if (peer.negotiationPending && connection.signalingState === 'stable') {
                await negotiatePeer(peer);
            }
        } else if (signal.type === 'media-state') {
            if (signal.payload?.source === 'screen' && signal.payload?.stream_id) {
                peer.screenStreamId = signal.payload.stream_id;
                peer.screenTrackId = signal.payload.track_id || null;
                const stream = peer.screenTrackId ? peer.receivedVideoStreams.get(peer.screenTrackId) : null;
                if (stream) {
                    peer.screenStream = stream;
                    peer.cameraStream = [...peer.receivedVideoStreams.entries()]
                        .find(([trackId]) => trackId !== peer.screenTrackId)?.[1] || null;
                    if (peer.cameraStream) peer.video.srcObject = peer.cameraStream;
                }
            } else if (signal.payload?.source === 'camera') {
                peer.screenStream = null;
                peer.screenStreamId = null;
                peer.screenTrackId = null;
            }
            updateScreenLayout();
        } else if (signal.type === 'candidate' && signal.payload?.candidate) {
            if (peer.ignoreOffer) return;
            if (!peer.remoteDescriptionReady) peer.pendingCandidates.push(signal.payload);
            else await connection.addIceCandidate(signal.payload);
        }
    };

    const consumeSignals = async () => {
        while (polling) {
            try {
                const response = await fetch(`${room.dataset.pollUrl}?after=${lastSignal}&session_id=${encodeURIComponent(roomSession)}`, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (response.status === 409) {
                    polling = false;
                    status.textContent = 'Sesi ruang diperbarui. Memuat ulang...';
                    window.setTimeout(() => window.location.reload(), 500);
                    break;
                }
                if (!response.ok) {
                    polling = false;
                    status.textContent = 'Sesi konsultasi telah berakhir.';
                    break;
                }
                const result = await response.json();
                roomCapacity = Number(result.capacity || roomCapacity);
                reconcileParticipants(result.participants || []);
                for (const signal of result.signals || []) {
                    lastSignal = Math.max(lastSignal, Number(signal.id));
                    const peer = peers.get(Number(signal.sender_user_id));
                    if (!peer) continue;
                    enqueuePeerOperation(peer, () => handleSignal(signal));
                }
            } catch (error) {
                status.textContent = `Koneksi ruang terputus: ${error.message}`;
            }
            const connectionPending = [...peers.values()].some((peer) => peer.connection.connectionState !== 'connected');
            const pollDelay = document.hidden ? 2500 : (connectionPending ? 600 : 1200);
            await new Promise((resolve) => window.setTimeout(resolve, pollDelay));
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
        if (!localStream.getTracks().length) status.textContent = 'Kamera dan mikrofon tidak tersedia; bergabung dalam mode dengar.';
        consumeSignals();
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
        await Promise.all([...peers.values()].map((peer) => {
            if (!peer.screenSender) return Promise.resolve();
            peer.connection.removeTrack(peer.screenSender);
            peer.screenSender = null;
            return Promise.resolve();
        }));
        await sendMediaState({ source: 'camera' });
        localVideo.srcObject = localStream || null;
        document.getElementById('share-screen').classList.remove('is-muted');
        document.getElementById('share-screen').setAttribute('aria-label', 'Bagikan layar');
        updateScreenQualityLabel();
        updateScreenLayout();
    };

    document.getElementById('share-screen').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        if (screenStream) {
            await stopScreenShare();
            return;
        }
        try {
            screenStream = await navigator.mediaDevices.getDisplayMedia({
                video: { width: { ideal: 1920, max: 1920 }, height: { ideal: 1080, max: 1080 }, frameRate: { ideal: 30, max: 60 } },
                audio: false
            });
            const screenTrack = screenStream.getVideoTracks()[0];
            await Promise.all([...peers.values()].map((peer) => {
                peer.screenSender = peer.connection.addTrack(screenTrack, screenStream);
                return Promise.resolve();
            }));
            await sendMediaState({ source: 'screen', stream_id: screenStream.id, track_id: screenTrack.id });
            if (selectedScreenQuality === 'auto') automaticScreenProfile = '1080p30';
            await applyScreenQuality();
            screenQualityTimer = window.setInterval(adaptScreenQuality, 5000);
            button.classList.add('is-muted');
            button.setAttribute('aria-label', 'Hentikan berbagi layar');
            updateScreenLayout();
            screenTrack.addEventListener('ended', () => stopScreenShare().catch((error) => { status.textContent = error.message; }), { once: true });
        } catch (error) {
            screenStream?.getTracks().forEach((track) => track.stop());
            screenStream = null;
            status.textContent = error.name === 'NotAllowedError' ? 'Izin berbagi layar dibatalkan.' : 'Berbagi layar tidak tersedia.';
        }
    });
    screenQualitySelect.addEventListener('change', async () => {
        selectedScreenQuality = screenQualitySelect.value;
        goodNetworkSamples = 0;
        if (selectedScreenQuality === 'auto') automaticScreenProfile = '1080p30';
        updateScreenQualityLabel();
        await applyScreenQuality();
    });
    const inviteForm = document.querySelector('[data-room-invite-form]');
    inviteForm?.addEventListener('submit', (event) => {
        const selectedCount = inviteForm.querySelectorAll('input[name="staff_user_ids[]"]:checked').length;
        const limit = Number(inviteForm.dataset.maxInvites);
        let error = inviteForm.querySelector('[data-invite-client-error]');
        if (!error) {
            error = document.createElement('small');
            error.className = 'text-danger';
            error.dataset.inviteClientError = '';
            error.setAttribute('role', 'alert');
            inviteForm.prepend(error);
        }
        if (selectedCount < 1 || selectedCount > limit) {
            event.preventDefault();
            error.textContent = `Pilih antara 1 hingga ${limit} petugas tambahan.`;
        } else {
            error.remove();
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
        await sendSignal('leave', null).catch(() => {});
        localStream?.getTracks().forEach((track) => track.stop());
        screenStream?.getTracks().forEach((track) => track.stop());
        for (const peer of peers.values()) peer.connection.close();
        window.location.assign(room.dataset.returnUrl);
    });
    window.addEventListener('beforeunload', () => {
        polling = false;
        localStream?.getTracks().forEach((track) => track.stop());
        screenStream?.getTracks().forEach((track) => track.stop());
        if (screenQualityTimer) window.clearInterval(screenQualityTimer);
        for (const peer of peers.values()) peer.connection.close();
    });

    updateScreenQualityLabel();
    startRoom().catch((error) => { status.textContent = `Gagal bergabung: ${error.message}`; });
})();

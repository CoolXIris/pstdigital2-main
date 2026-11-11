(() => {
    const room = document.getElementById('consultation-room');
    if (!room) return;

    const gallery = document.getElementById('room-gallery');
    const cameraStrip = document.getElementById('room-camera-strip');
    const screenStage = document.getElementById('room-screen-stage');
    const screenVideo = document.getElementById('room-screen-video');
    const screenLabel = document.getElementById('room-screen-label');
    const pinnedStage = document.getElementById('room-pinned-stage');
    const pinnedVideo = document.getElementById('room-pinned-video');
    const pinnedLabel = document.getElementById('room-pinned-label');
    const unpinCameraButton = document.getElementById('room-unpin-camera');
    const pinnedAvatar = document.getElementById('room-pinned-avatar');
    const status = document.getElementById('room-status');
    const countLabel = document.getElementById('room-participant-count');
    const placeholder = document.getElementById('remote-placeholder');
    const moderationNotice = document.getElementById('room-moderation-notice');
    const endingNotice = document.getElementById('room-ending-notice');
    const endingCountdown = document.getElementById('room-ending-countdown');
    const localVideo = document.createElement('video');
    const requester = room.dataset.isRequester === 'true';
    const canModerate = room.dataset.canModerate === 'true';
    const currentUserId = Number(room.dataset.currentUserId);
    const roomSession = room.dataset.roomSession;
    const roomEndsAt = new Date(room.dataset.roomEndsAt).getTime();
    let serverTimeOffset = new Date(room.dataset.roomServerNow).getTime() - Date.now();
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

    const createMediaIndicators = (tile) => {
        const indicators = document.createElement('div');
        indicators.className = 'portal-room-media-indicators';
        indicators.setAttribute('aria-label', 'Status mikrofon dan kamera');
        const controls = {};
        for (const [kind, icon, label] of [
            ['audio', 'bi-mic-fill', 'Mikrofon'],
            ['video', 'bi-camera-video-fill', 'Kamera']
        ]) {
            const indicator = document.createElement('span');
            indicator.className = 'portal-room-media-indicator';
            indicator.dataset.mediaKind = kind;
            indicator.dataset.enabled = 'false';
            indicator.setAttribute('role', 'img');
            indicator.setAttribute('aria-label', `${label} mati`);
            indicator.title = `${label} mati`;
            const glyph = document.createElement('i');
            glyph.className = `bi ${icon}`;
            indicator.append(glyph);
            indicators.append(indicator);
            controls[kind] = indicator;
        }
        tile.append(indicators);
        return controls;
    };

    const localMediaIndicators = createMediaIndicators(localTile);
    const renderAvatarContent = (avatar, participant) => {
        avatar.replaceChildren();
        avatar.dataset.participantId = String(participant.id || '');
        avatar.hidden = false;
        const initials = (participant.name || '?').trim().charAt(0).toUpperCase() || '?';
        if (participant.picture) {
            const image = document.createElement('img');
            image.src = participant.picture;
            image.alt = '';
            image.referrerPolicy = 'no-referrer';
            image.addEventListener('error', () => {
                image.remove();
                avatar.textContent = initials;
            }, { once: true });
            avatar.append(image);
        } else {
            avatar.textContent = initials;
        }
    };
    const createCameraOffAvatar = (tile, participant) => {
        const avatar = document.createElement('div');
        avatar.className = 'portal-room-camera-off';
        avatar.hidden = true;
        renderAvatarContent(avatar, participant);
        avatar.hidden = true;
        tile.append(avatar);
        return avatar;
    };
    const localAvatar = createCameraOffAvatar(localTile, {
        id: currentUserId,
        name: room.dataset.currentUserName,
        picture: room.dataset.currentUserPicture
    });
    let localStream;
    let screenStream;
    let screenQualityTimer = null;
    let screenQualityBusy = false;
    let selectedScreenQuality = 'auto';
    let automaticScreenProfile = '1080p30';
    let goodNetworkSamples = 0;
    let lastSignal = Number(room.dataset.lastSignalId || 0);
    let polling = true;
    let roomEnding = false;
    let pinnedParticipantId = null;
    let moderationNoticeTimer = null;
    let roomEndTimer = null;
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

    const sendSignal = async (type, payload, recipientUserId = null, options = {}) => {
        const response = await fetch(room.dataset.signalsUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ type, payload, session_id: roomSession, recipient_user_id: recipientUserId }),
            signal: options.signal
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

    const getLocalDeviceState = () => ({
        audio: Boolean(localStream?.getAudioTracks().some((track) => track.readyState === 'live' && track.enabled)),
        video: Boolean(localStream?.getVideoTracks().some((track) => track.readyState === 'live' && track.enabled))
    });

    const updateMediaIndicators = (indicators, state) => {
        for (const kind of ['audio', 'video']) {
            const enabled = Boolean(state[kind]);
            const indicator = indicators[kind];
            const label = kind === 'audio' ? 'Mikrofon' : 'Kamera';
            indicator.dataset.enabled = enabled ? 'true' : 'false';
            indicator.setAttribute('aria-label', `${label} ${enabled ? 'aktif' : 'mati'}`);
            indicator.title = `${label} ${enabled ? 'aktif' : 'mati'}`;
            indicator.firstElementChild.className = `bi ${kind === 'audio'
                ? (enabled ? 'bi-mic-fill' : 'bi-mic-mute-fill')
                : (enabled ? 'bi-camera-video-fill' : 'bi-camera-video-off-fill')}`;
        }
    };

    const updateCameraAvatar = (avatar, enabled) => {
        avatar.hidden = enabled;
    };

    const publishDeviceState = async (recipientUserId = null) => {
        const state = getLocalDeviceState();
        updateMediaIndicators(localMediaIndicators, state);
        const recipients = recipientUserId === null ? [...peers.keys()] : [recipientUserId];
        await Promise.all(recipients.map(async (id) => {
            try {
                await sendSignal('media-state', { source: 'devices', ...state }, id);
            } catch (error) {
                status.textContent = `Status mic/kamera tidak tersinkron: ${error.message}`;
            }
        }));
    };

    const updateMediaControl = (kind, enabled) => {
        const button = document.getElementById(kind === 'audio' ? 'toggle-mic' : 'toggle-camera');
        button.classList.toggle('is-muted', !enabled);
        button.setAttribute('aria-label', `${enabled ? 'Matikan' : 'Nyalakan'} ${kind === 'audio' ? 'mikrofon' : 'kamera'}`);
        if (kind === 'video') updateCameraAvatar(localAvatar, enabled);
        publishDeviceState();
    };

    const monitorLocalTrack = (track) => {
        track.addEventListener('ended', () => {
            updateMediaControl(track.kind, false);
        }, { once: true });
    };

    const enableLocalMedia = async (kind) => {
        let track = localStream?.getTracks().find((item) => item.kind === kind && item.readyState === 'live');
        if (!track) {
            const captured = await navigator.mediaDevices.getUserMedia(kind === 'audio'
                ? { audio: true, video: false }
                : { audio: false, video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 24, max: 30 } } });
            track = captured.getTracks().find((item) => item.kind === kind);
            if (!track) {
                captured.getTracks().forEach((item) => item.stop());
                throw new Error('Perangkat media tidak menghasilkan track yang diminta.');
            }
            if (!localStream) localStream = new MediaStream();
            localStream.addTrack(track);
            monitorLocalTrack(track);
            localVideo.srcObject = localStream;
            for (const peer of peers.values()) {
                const senderKey = kind === 'audio' ? 'audioSender' : 'videoSender';
                const transceiverKey = kind === 'audio' ? 'audioTransceiver' : 'videoTransceiver';
                let sender = peer[senderKey];
                let transceiver = peer[transceiverKey]
                    || peer.connection.getTransceivers().find((item) => item.sender === sender);
                if (!sender) {
                    sender = peer.connection.addTrack(track, localStream);
                    peer[senderKey] = sender;
                } else {
                    await sender.replaceTrack(track);
                }
                if (transceiver?.direction === 'recvonly') transceiver.direction = 'sendrecv';
                peer[transceiverKey] = transceiver || null;
                requestNegotiation(peer);
            }
        }
        track.enabled = true;
        updateMediaControl(kind, true);
    };

    const showModerationNotice = (message, actionLabel, onAction) => {
        if (moderationNoticeTimer) window.clearTimeout(moderationNoticeTimer);
        moderationNotice.replaceChildren();
        const text = document.createElement('span');
        text.textContent = message;
        moderationNotice.append(text);
        if (actionLabel && onAction) {
            const acceptButton = document.createElement('button');
            acceptButton.type = 'button';
            acceptButton.textContent = actionLabel;
            acceptButton.addEventListener('click', async () => {
                acceptButton.disabled = true;
                try {
                    await onAction();
                    moderationNotice.hidden = true;
                } catch (error) {
                    text.textContent = `${message} (${error.message})`;
                    acceptButton.disabled = false;
                }
            }, { once: true });
            moderationNotice.append(acceptButton);
        }
        const dismissButton = document.createElement('button');
        dismissButton.type = 'button';
        dismissButton.textContent = 'Tutup';
        dismissButton.addEventListener('click', () => { moderationNotice.hidden = true; });
        moderationNotice.append(dismissButton);
        moderationNotice.hidden = false;
        moderationNoticeTimer = window.setTimeout(() => { moderationNotice.hidden = true; }, 20000);
    };

    const requestParticipantAction = async (participant, action) => {
        if (!canModerate) return;
        if (action === 'kick' && !window.confirm(`Keluarkan ${participant.name} dari ruang sampai sesi berakhir?`)) return;
        try {
            await sendSignal('moderation', { action }, Number(participant.id));
            if (action === 'kick') status.textContent = `${participant.name} dikeluarkan dari ruang.`;
        } catch (error) {
            status.textContent = `Tindakan peserta gagal: ${error.message}`;
        }
    };

    const createTile = (participant) => {
        const tile = document.createElement('article');
        tile.className = 'portal-room-tile';
        tile.dataset.participantId = String(participant.id);
        const cameraAvatar = createCameraOffAvatar(tile, participant);
        const mediaIndicators = createMediaIndicators(tile);
        updateMediaIndicators(mediaIndicators, { audio: false, video: false });
        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        tile.append(video);
        const actions = document.createElement('details');
        actions.className = 'portal-room-tile-actions';
        const menuToggle = document.createElement('summary');
        menuToggle.textContent = 'Opsi';
        menuToggle.setAttribute('aria-label', `Opsi kamera ${participant.name}`);
        actions.append(menuToggle);
        const actionList = document.createElement('div');
        actionList.className = 'portal-room-tile-options';
        const pinButton = document.createElement('button');
        pinButton.type = 'button';
        pinButton.textContent = 'Sematkan';
        pinButton.setAttribute('aria-label', `Sematkan kamera ${participant.name}`);
        pinButton.addEventListener('click', () => {
            pinnedParticipantId = pinnedParticipantId === Number(participant.id) ? null : Number(participant.id);
            actions.open = false;
            updateScreenLayout();
        });
        actionList.append(pinButton);
        if (canModerate) {
            const controls = [
                ['request-camera', 'Minta kamera'],
                ['request-mic', 'Minta mikrofon'],
                ['mute-mic', 'Bisukan mikrofon'],
                ['kick', 'Keluarkan']
            ];
            for (const [action, label] of controls) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.title = `${label} ${participant.name}`;
                if (action === 'kick') button.classList.add('is-danger');
                button.addEventListener('click', async () => {
                    await requestParticipantAction(participant, action);
                    actions.open = false;
                });
                actionList.append(button);
            }
        }
        actions.append(actionList);
        tile.append(actions);
        const label = document.createElement('span');
        label.textContent = `${participant.name}${participant.is_requester ? ' · Pemohon' : ' · Petugas'}`;
        tile.append(label);
        cameraStrip.insertBefore(tile, placeholder);
        return { tile, video, pinButton, mediaIndicators, cameraAvatar };
    };

    const updateScreenLayout = () => {
        const activeSharer = [...peers.values()].find((peer) => peer.screenStream);
        const localSharing = Boolean(screenStream);
        const sharing = localSharing || Boolean(activeSharer);
        const pinnedPeer = pinnedParticipantId === null ? null : peers.get(pinnedParticipantId);
        const pinnedCamera = !sharing && pinnedPeer;
        gallery.classList.toggle('has-screen-share', sharing);
        gallery.classList.toggle('has-pinned-camera', Boolean(pinnedCamera));
        gallery.classList.toggle('has-feature-stage', sharing || Boolean(pinnedCamera));
        screenStage.hidden = !sharing;
        pinnedStage.hidden = !pinnedCamera;
        const screenSource = localSharing ? screenStream : activeSharer?.screenStream || null;
        if (screenSource) {
            if (screenVideo.srcObject !== screenSource) screenVideo.srcObject = screenSource;
            screenVideo.muted = localSharing;
            screenVideo.controls = !localSharing;
            screenLabel.textContent = localSharing
                ? 'Berbagi layar · Anda'
                : `Berbagi layar · ${activeSharer.name}`;
            screenVideo.play().catch(() => {
                if (!localSharing) status.textContent = 'Klik video berbagi layar untuk mengaktifkan audionya.';
            });
        } else {
            if (screenVideo.srcObject) screenVideo.srcObject = null;
            screenVideo.muted = false;
            screenVideo.controls = false;
            screenLabel.textContent = '';
        }
        if (pinnedCamera) {
            if (pinnedVideo.srcObject !== pinnedPeer.cameraStream) pinnedVideo.srcObject = pinnedPeer.cameraStream;
            pinnedLabel.textContent = `Disematkan · ${pinnedPeer.name}`;
            if (pinnedAvatar.dataset.participantId !== String(pinnedPeer.id)) {
                renderAvatarContent(pinnedAvatar, {
                    id: pinnedPeer.id,
                    name: pinnedPeer.name,
                    picture: pinnedPeer.picture
                });
            }
            pinnedAvatar.hidden = Boolean(pinnedPeer.deviceState.video);
        } else {
            if (pinnedVideo.srcObject) pinnedVideo.srcObject = null;
            pinnedLabel.textContent = '';
            pinnedAvatar.hidden = true;
        }
        for (const [id, peer] of peers) {
            peer.tile.classList.toggle('is-pinned', id === pinnedParticipantId);
            peer.pinButton.classList.toggle('is-active', id === pinnedParticipantId);
            peer.pinButton.textContent = id === pinnedParticipantId ? 'Lepas sematan' : 'Sematkan';
            peer.pinButton.setAttribute('aria-pressed', id === pinnedParticipantId ? 'true' : 'false');
        }
    };
    unpinCameraButton.addEventListener('click', () => {
        pinnedParticipantId = null;
        updateScreenLayout();
    });

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
        const { tile, video, pinButton, mediaIndicators, cameraAvatar } = createTile(participant);
        const remoteStream = new MediaStream();
        const audioTrack = localStream?.getAudioTracks()[0];
        const videoTrack = localStream?.getVideoTracks()[0] || null;
        let audioSender = null;
        let audioTransceiver = null;
        let videoSender = null;
        let videoTransceiver = null;
        if (audioTrack) audioSender = connection.addTrack(audioTrack, localStream);
        else {
            audioTransceiver = connection.addTransceiver('audio', { direction: 'recvonly' });
            audioSender = audioTransceiver.sender;
        }
        if (videoTrack) videoSender = connection.addTrack(videoTrack, localStream);
        else {
            videoTransceiver = connection.addTransceiver('video', { direction: 'sendrecv' });
            videoSender = videoTransceiver.sender;
        }
        const activeScreenTrack = screenStream?.getVideoTracks()[0] || null;
        const screenSender = activeScreenTrack ? connection.addTrack(activeScreenTrack, screenStream) : null;
        const activeScreenAudioTrack = screenStream?.getAudioTracks()[0] || null;
        const screenAudioSender = activeScreenAudioTrack ? connection.addTrack(activeScreenAudioTrack, screenStream) : null;

        const peer = {
            id,
            name: participant.name,
            connection,
            tile,
            video,
            pinButton,
            mediaIndicators,
            cameraAvatar,
            picture: participant.picture,
            deviceState: { audio: false, video: false },
            audioSender,
            audioTransceiver,
            videoSender,
            videoTransceiver,
            screenStream: null,
            screenSender,
            screenAudioSender,
            screenStreamId: screenStream?.id || null,
            screenTrackId: activeScreenTrack?.id || null,
            receivedVideoStreams: new Map(),
            cameraStream: null,
            initialized: false,
            initialOfferer: currentUserId < id,
            makingOffer: false,
            polite: currentUserId > id,
            ignoreOffer: false,
            settingRemoteAnswer: false,
            answeringRemoteOffer: false,
            negotiationPending: Boolean(screenSender || screenAudioSender),
            remoteDescriptionReady: false,
            pendingCandidates: [],
            operationChain: Promise.resolve(),
            iceRestarts: 0
        };
        peers.set(id, peer);
        publishDeviceState(id);
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
            if (incomingStream && event.track.kind === 'video') peer.receivedVideoStreams.set(event.track.id, incomingStream);
            if (incomingStream && (incomingStream.id === peer.screenStreamId || event.track.id === peer.screenTrackId)) {
                peer.screenStream = incomingStream;
                peer.cameraStream = [...peer.receivedVideoStreams.entries()]
                    .find(([trackId]) => trackId !== peer.screenTrackId)?.[1] || null;
            } else if (event.track.id === peer.screenTrackId) {
                peer.screenStream = new MediaStream([event.track]);
            } else if (event.track.kind === 'video') {
                peer.cameraStream = incomingStream || new MediaStream([event.track]);
                if (video.srcObject !== peer.cameraStream) video.srcObject = peer.cameraStream;
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
            if (pinnedParticipantId === id) pinnedParticipantId = null;
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
            if (pinnedParticipantId === peer.id) pinnedParticipantId = null;
            setRoomStatus();
            return;
        }
        if (signal.type === 'moderation') {
            const action = signal.payload?.action;
            if (action === 'request-camera') {
                showModerationNotice(`${peer.name} meminta Anda menyalakan kamera.`, 'Nyalakan kamera', () => enableLocalMedia('video'));
            } else if (action === 'request-mic') {
                showModerationNotice(`${peer.name} meminta Anda menyalakan mikrofon.`, 'Nyalakan mikrofon', () => enableLocalMedia('audio'));
            } else if (action === 'mute-mic') {
                const audioTrack = localStream?.getAudioTracks().find((track) => track.readyState === 'live');
                if (audioTrack) audioTrack.enabled = false;
                updateMediaControl('audio', false);
                showModerationNotice(`${peer.name} membisukan mikrofon Anda. Anda dapat menyalakannya kembali kapan saja.`);
            }
            return;
        }
        if (signal.type === 'media-state' && signal.payload?.source === 'devices') {
            peer.deviceState = {
                audio: Boolean(signal.payload.audio),
                video: Boolean(signal.payload.video)
            };
            updateMediaIndicators(peer.mediaIndicators, peer.deviceState);
            updateCameraAvatar(peer.cameraAvatar, peer.deviceState.video);
            if (pinnedParticipantId === peer.id) pinnedAvatar.hidden = peer.deviceState.video;
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

    const finishRoom = async (reason) => {
        if (roomEnding) return;
        roomEnding = true;
        polling = false;
        status.textContent = reason;
        if (screenQualityTimer) window.clearInterval(screenQualityTimer);
        if (roomEndTimer) window.clearInterval(roomEndTimer);
        if (moderationNoticeTimer) window.clearTimeout(moderationNoticeTimer);
        const leaveController = new AbortController();
        const leaveTimeout = window.setTimeout(() => leaveController.abort(), 1500);
        try {
            await sendSignal('leave', null, null, { signal: leaveController.signal });
        } catch {
            // The server may already have expired or removed this participant's lease.
        } finally {
            window.clearTimeout(leaveTimeout);
        }
        localStream?.getTracks().forEach((track) => track.stop());
        screenStream?.getTracks().forEach((track) => track.stop());
        for (const peer of peers.values()) peer.connection.close();
        window.location.assign(room.dataset.returnUrl);
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
                if (response.status === 410) {
                    await finishRoom('Sesi konsultasi telah berakhir.');
                    break;
                }
                if (response.status === 403) {
                    await finishRoom('Anda sudah tidak berada di ruang konsultasi.');
                    break;
                }
                if (!response.ok) {
                    polling = false;
                    status.textContent = 'Sesi konsultasi telah berakhir.';
                    break;
                }
                const result = await response.json();
                if (result.server_now) serverTimeOffset = new Date(result.server_now).getTime() - Date.now();
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
        if (roomEnding) return;
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
        if (roomEnding) {
            tracks.forEach((track) => track.stop());
            return;
        }
        localStream = new MediaStream(tracks);
        localStream.getTracks().forEach(monitorLocalTrack);
        localVideo.srcObject = localStream;
        const deviceState = getLocalDeviceState();
        updateMediaIndicators(localMediaIndicators, deviceState);
        updateCameraAvatar(localAvatar, deviceState.video);
        updateMediaControl('audio', deviceState.audio);
        updateMediaControl('video', deviceState.video);
        if (!localStream.getTracks().length) status.textContent = 'Kamera dan mikrofon tidak tersedia; bergabung dalam mode dengar.';
        consumeSignals();
    };

    document.getElementById('toggle-mic').addEventListener('click', async () => {
        const track = localStream?.getAudioTracks().find((item) => item.readyState === 'live');
        try {
            if (!track) {
                await enableLocalMedia('audio');
                return;
            }
            track.enabled = !track.enabled;
            updateMediaControl('audio', track.enabled);
        } catch (error) {
            status.textContent = `Mikrofon tidak dapat diaktifkan: ${error.message}`;
        }
    });
    document.getElementById('toggle-camera').addEventListener('click', async () => {
        const track = localStream?.getVideoTracks().find((item) => item.readyState === 'live');
        try {
            if (!track) {
                await enableLocalMedia('video');
                return;
            }
            track.enabled = !track.enabled;
            updateMediaControl('video', track.enabled);
        } catch (error) {
            status.textContent = `Kamera tidak dapat diaktifkan: ${error.message}`;
        }
    });

    const stopScreenShare = async () => {
        const activeScreen = screenStream;
        if (!activeScreen) return;
        screenStream = null;
        if (screenQualityTimer) window.clearInterval(screenQualityTimer);
        screenQualityTimer = null;
        activeScreen.getTracks().forEach((track) => track.stop());
        await Promise.all([...peers.values()].map((peer) => {
            if (peer.screenSender) peer.connection.removeTrack(peer.screenSender);
            if (peer.screenAudioSender) peer.connection.removeTrack(peer.screenAudioSender);
            peer.screenSender = null;
            peer.screenAudioSender = null;
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
                audio: true
            });
            const screenTrack = screenStream.getVideoTracks()[0];
            const screenAudioTrack = screenStream.getAudioTracks()[0] || null;
            await sendMediaState({ source: 'screen', stream_id: screenStream.id, track_id: screenTrack.id });
            await Promise.all([...peers.values()].map((peer) => {
                peer.screenSender = peer.connection.addTrack(screenTrack, screenStream);
                peer.screenAudioSender = screenAudioTrack
                    ? peer.connection.addTrack(screenAudioTrack, screenStream)
                    : null;
                return Promise.resolve();
            }));
            if (!screenAudioTrack) {
                status.textContent = 'Audio tidak tertangkap. Pilih tab browser dan aktifkan opsi berbagi audio tab.';
            }
            if (selectedScreenQuality === 'auto') automaticScreenProfile = '1080p30';
            await applyScreenQuality();
            screenQualityTimer = window.setInterval(adaptScreenQuality, 5000);
            button.classList.add('is-muted');
            button.setAttribute('aria-label', 'Hentikan berbagi layar');
            updateScreenLayout();
            screenTrack.addEventListener('ended', () => stopScreenShare().catch((error) => { status.textContent = error.message; }), { once: true });
        } catch (error) {
            if (screenStream) {
                try {
                    await stopScreenShare();
                } catch (cleanupError) {
                    status.textContent = `Berbagi layar gagal: ${error.message}. Pembersihan media juga gagal: ${cleanupError.message}`;
                    return;
                }
            }
            status.textContent = error.name === 'NotAllowedError'
                ? 'Izin berbagi layar dibatalkan.'
                : `Berbagi layar tidak tersedia: ${error.message}`;
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
    inviteForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const selectedCount = inviteForm.querySelectorAll('input[name="staff_user_ids[]"]:checked').length;
        const limit = Number(inviteForm.dataset.maxInvites);
        const inviteStatus = inviteForm.querySelector('[data-room-invite-status]');
        if (selectedCount < 1 || selectedCount > limit) {
            inviteStatus.className = 'text-danger';
            inviteStatus.textContent = `Pilih antara 1 hingga ${limit} petugas tambahan.`;
            return;
        }
        const submitButton = inviteForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        inviteStatus.className = '';
        inviteStatus.textContent = 'Menyimpan undangan...';
        try {
            const response = await fetch(inviteForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(inviteForm)
            });
            const result = await response.json();
            if (!response.ok) {
                const message = result.errors?.staff_user_ids?.[0]
                    || result.message
                    || 'Undangan petugas gagal disimpan.';
                throw new Error(message);
            }

            const invitedList = document.querySelector('[data-room-invited-list]');
            invitedList.replaceChildren();
            if (result.invited_users.length) {
                const heading = document.createElement('strong');
                heading.textContent = 'Petugas yang diundang:';
                invitedList.append(heading);
                for (const invitedUser of result.invited_users) {
                    const name = document.createElement('span');
                    name.textContent = invitedUser.name;
                    invitedList.append(name);
                }
                invitedList.hidden = false;
            } else {
                invitedList.hidden = true;
            }
            for (const checkbox of inviteForm.querySelectorAll('input[name="staff_user_ids[]"]:checked')) {
                checkbox.closest('label').remove();
            }
            inviteForm.dataset.totalInvites = String(result.invited_users.length);
            inviteForm.dataset.maxInvites = String(Math.max(0, 2 - result.invited_users.length));
            roomCapacity = Number(result.capacity);
            room.dataset.roomCapacity = String(roomCapacity);
            setRoomStatus();
            if (Number(inviteForm.dataset.maxInvites) === 0) {
                inviteForm.remove();
                const capacityNotice = document.createElement('small');
                capacityNotice.dataset.roomInviteCapacity = '';
                capacityNotice.textContent = 'Batas dua petugas tambahan sudah tercapai.';
                document.querySelector('.portal-room-invite').append(capacityNotice);
            } else if (!inviteForm.querySelector('input[name="staff_user_ids[]"]')) {
                inviteForm.remove();
                const availabilityNotice = document.createElement('small');
                availabilityNotice.textContent = 'Tidak ada akun petugas lain yang tersedia.';
                document.querySelector('.portal-room-invite').append(availabilityNotice);
            }
            inviteStatus.className = 'text-success';
            inviteStatus.textContent = 'Petugas berhasil diundang tanpa memutus koneksi ruang.';
        } catch (error) {
            inviteStatus.className = 'text-danger';
            inviteStatus.textContent = error.message;
            submitButton.disabled = false;
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
    document.getElementById('leave-room').addEventListener('click', () => finishRoom('Anda keluar dari ruang konsultasi.'));
    window.addEventListener('beforeunload', () => {
        polling = false;
        localStream?.getTracks().forEach((track) => track.stop());
        screenStream?.getTracks().forEach((track) => track.stop());
        if (screenQualityTimer) window.clearInterval(screenQualityTimer);
        if (roomEndTimer) window.clearInterval(roomEndTimer);
        for (const peer of peers.values()) peer.connection.close();
    });

    const updateRoomEndCountdown = () => {
        const remaining = roomEndsAt - (Date.now() + serverTimeOffset);
        if (remaining <= 0) {
            finishRoom('Waktu konsultasi telah berakhir.');
            return;
        }
        if (remaining <= 5 * 60 * 1000) {
            endingNotice.hidden = false;
            const totalSeconds = Math.ceil(remaining / 1000);
            const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const seconds = String(totalSeconds % 60).padStart(2, '0');
            endingCountdown.textContent = `${minutes}:${seconds}`;
        }
    };

    updateRoomEndCountdown();
    roomEndTimer = window.setInterval(updateRoomEndCountdown, 1000);
    updateScreenQualityLabel();
    startRoom().catch((error) => { status.textContent = `Gagal bergabung: ${error.message}`; });
})();

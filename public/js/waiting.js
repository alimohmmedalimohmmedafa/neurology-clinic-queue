(function () {
    'use strict';

    var POLL_MS = 3500;
    var AUDIO_SRC = '/sounds/call-notification.mp3';

    var STAGE_LABELS = {
        consultation: 'المقابلة',
        review: 'النتيجة'
    };

    // One calm clip for every call. The review queue plays it a little slower
    // (lower pitch, preservesPitch off) so the two calls sound different.
    var CALL_RATES = {
        consultation: 1,
        review: 0.82
    };

    var players = {};
    var unlocked = false;
    var priming = false;

    var hasLoaded = false;
    var lastToken = null;
    var currentStage = 'consultation';

    function byId(id) {
        return typeof document !== 'undefined' ? document.getElementById(id) : null;
    }

    var idlePanel = byId('idle-panel');
    var pausedPanel = byId('paused-panel');
    var callPanel = byId('call-panel');
    var serviceLabel = byId('service-label');
    var queueNumber = byId('queue-number');
    var audioHint = byId('audio-hint');
    var testButton = byId('btn-test-audio');

    function hideHint() {
        if (audioHint) {
            audioHint.hidden = true;
        }
    }

    function audioCtor() {
        if (typeof Audio !== 'undefined') {
            return Audio;
        }

        if (typeof window !== 'undefined' && window.Audio) {
            return window.Audio;
        }

        return null;
    }

    function playerFor(stage) {
        var Context = audioCtor();
        if (!Context) {
            return null;
        }

        var rate = CALL_RATES[stage] || 1;

        if (!players[rate]) {
            try {
                var audio = new Context(AUDIO_SRC);
                audio.preload = 'auto';
                audio.preservesPitch = false;
                audio.mozPreservesPitch = false;
                audio.webkitPreservesPitch = false;
                audio.playbackRate = rate;
                players[rate] = audio;
            } catch (error) {
                return null;
            }
        }

        return players[rate];
    }

    // Playing must never break the display: every failure is swallowed.
    function playSound(stage) {
        var audio = playerFor(stage);

        if (!audio) {
            return false;
        }

        try {
            audio.currentTime = 0;
            var result = audio.play();

            if (result && typeof result.catch === 'function') {
                result.catch(function () {});
            }

            return true;
        } catch (error) {
            return false;
        }
    }

    // Browsers block sound until the first user gesture. We play the clip once
    // muted to satisfy that policy, then stop it. Any failure is ignored.
    function unlockAudio() {
        if (unlocked) {
            return Promise.resolve(true);
        }

        var audio = playerFor('consultation');

        if (!audio || priming) {
            return Promise.resolve(false);
        }

        priming = true;

        try {
            audio.muted = true;
            var result = audio.play();

            var done = function () {
                try {
                    audio.pause();
                    audio.currentTime = 0;
                } catch (error) {
                    // ignore
                }
                audio.muted = false;
                unlocked = true;
                priming = false;
                hideHint();
                return true;
            };

            var fail = function () {
                audio.muted = false;
                priming = false;
                return false;
            };

            if (result && typeof result.then === 'function') {
                return result.then(done).catch(fail);
            }

            return Promise.resolve(done());
        } catch (error) {
            priming = false;
            return Promise.resolve(false);
        }
    }

    function showPanel(panel) {
        [idlePanel, pausedPanel, callPanel].forEach(function (element) {
            if (element) {
                element.hidden = element !== panel;
            }
        });
    }

    // A null token means "nothing on screen right now".
    function tokenOf(state) {
        if (state.visit_id === null || state.visit_id === undefined) {
            return null;
        }

        if (state.call_token) {
            return state.call_token;
        }

        return state.visit_id + ':' + state.stage;
    }

    function render(state) {
        if (state.visit_id === null || state.visit_id === undefined) {
            showPanel(idlePanel);
        } else if (state.is_paused) {
            showPanel(pausedPanel);
        } else {
            if (serviceLabel) {
                serviceLabel.textContent = STAGE_LABELS[state.stage] || '';
            }
            if (queueNumber) {
                queueNumber.textContent = state.queue_number;
            }
            showPanel(callPanel);
        }

        if (state.stage) {
            currentStage = state.stage;
        }

        var token = tokenOf(state);

        // Ring once per real call only. The first poll after opening the screen
        // (or a plain refresh) never rings, because the token is unchanged.
        if (hasLoaded && token !== null && token !== lastToken) {
            playSound(state.stage || currentStage);
        }

        lastToken = token;
        hasLoaded = true;
    }

    function poll() {
        return fetch('/api/queue/display', { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(render)
            .catch(function () {});
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('click', function () { unlockAudio(); });
        document.addEventListener('keydown', function () { unlockAudio(); });
    }

    if (testButton) {
        testButton.addEventListener('click', function () {
            unlockAudio().then(function () {
                playSound(currentStage);
            });
        });
    }

    unlockAudio();
    poll();
    setInterval(poll, POLL_MS);
})();

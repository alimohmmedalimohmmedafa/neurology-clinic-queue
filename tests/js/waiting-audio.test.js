import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '..', '..', 'public', 'js', 'waiting.js'), 'utf8');

function flush() {
    return new Promise((resolve) => setImmediate(resolve));
}

function makeNode(id) {
    return {
        id,
        hidden: false,
        textContent: '',
        listeners: {},
        addEventListener(type, handler) {
            (this.listeners[type] = this.listeners[type] || []).push(handler);
        },
        click() {
            (this.listeners.click || []).forEach((handler) => handler());
        }
    };
}

function boot(initial = []) {
    const plays = [];
    const nodes = new Map();
    const queue = [...initial];
    let intervalCallback = null;

    class FakeAudio {
        constructor(src) {
            this.src = src;
            this.currentTime = 0;
            this.playbackRate = 1;
            this.muted = false;
            this.preload = '';
        }
        play() {
            plays.push({ src: this.src, rate: this.playbackRate, muted: this.muted });
            return Promise.resolve();
        }
        pause() {}
    }

    const documentStub = {
        listeners: {},
        getElementById(id) {
            if (!nodes.has(id)) {
                nodes.set(id, makeNode(id));
            }
            return nodes.get(id);
        },
        addEventListener(type, handler) {
            (this.listeners[type] = this.listeners[type] || []).push(handler);
        }
    };

    const sandbox = {
        Audio: FakeAudio,
        window: { Audio: FakeAudio },
        document: documentStub,
        fetch() {
            const payload = queue.shift() ?? { visit_id: null, stage: null, is_paused: false, call_token: null };
            return Promise.resolve({ json: () => Promise.resolve(payload) });
        },
        setInterval(callback) {
            intervalCallback = callback;
            return 1;
        },
        clearInterval() {},
        setTimeout,
        clearTimeout,
        console
    };
    sandbox.globalThis = sandbox;

    vm.runInNewContext(source, sandbox, { filename: 'waiting.js' });

    return {
        plays,
        alerts() {
            return plays.filter((play) => !play.muted);
        },
        feed(payload) {
            queue.push(payload);
        },
        poll() {
            return intervalCallback ? intervalCallback() : Promise.resolve();
        },
        testButton() {
            return documentStub.getElementById('btn-test-audio');
        },
        hint() {
            return documentStub.getElementById('audio-hint');
        }
    };
}

function consultationCall(id, token) {
    return { visit_id: id, queue_number: '00' + id, stage: 'consultation', is_paused: false, call_token: token };
}

function reviewCall(id, token) {
    return { visit_id: id, queue_number: '00' + id, stage: 'review', is_paused: false, call_token: token };
}

test('the call clip is the shared notification file', async () => {
    const app = boot([consultationCall(1, '100-1')]);
    await flush();

    app.feed(consultationCall(2, '101-2'));
    await app.poll();

    assert.equal(app.alerts().length, 1);
    assert.equal(app.alerts()[0].src, '/sounds/call-notification.mp3');
});

test('opening the screen while a call is already active does not ring', async () => {
    const app = boot([consultationCall(1, '100-1')]);
    await flush();

    assert.equal(app.alerts().length, 0);
    assert.equal(app.plays.length, 1, 'the clip is primed muted once for autoplay');
    assert.equal(app.plays[0].muted, true);
    assert.equal(app.hint().hidden, true, 'the click hint is hidden once audio is ready');
});

test('a plain refresh never replays the sound', async () => {
    const app = boot([consultationCall(1, '100-1')]);
    await flush();

    for (let i = 0; i < 5; i++) {
        app.feed(consultationCall(1, '100-1'));
        await app.poll();
    }

    assert.equal(app.alerts().length, 0);
});

test('each new consultation call rings once', async () => {
    const app = boot([consultationCall(1, '100-1')]);
    await flush();

    app.feed(consultationCall(2, '101-2'));
    await app.poll();

    assert.equal(app.alerts().length, 1);
    assert.equal(app.alerts()[0].rate, 1);

    app.feed(consultationCall(2, '101-2'));
    await app.poll();
    assert.equal(app.alerts().length, 1, 'the same call must not ring again');
});

test('a review call plays a distinct (slower) sound', async () => {
    const app = boot([consultationCall(1, '100-1')]);
    await flush();

    app.feed(reviewCall(2, '200-2'));
    await app.poll();

    assert.equal(app.alerts().length, 1);
    assert.equal(app.alerts()[0].rate, 0.82);
    assert.notEqual(app.alerts()[0].rate, 1, 'review differs from consultation');
});

test('the same patient called in review plays the review sound', async () => {
    const app = boot([consultationCall(1, '100-1')]);
    await flush();

    app.feed(reviewCall(1, '300-1'));
    await app.poll();

    assert.equal(app.alerts().length, 1);
    assert.equal(app.alerts()[0].rate, 0.82);
});

test('the test button plays the sound of the current stage', async () => {
    const app = boot([reviewCall(1, '100-1')]);
    await flush();

    app.plays.length = 0;
    app.testButton().click();
    await flush();

    assert.equal(app.alerts().length, 1);
    assert.equal(app.alerts()[0].rate, 0.82);
});

test('an audio failure never breaks the display update', async () => {
    const failing = {
        Audio: class { play() { return Promise.reject(new Error('blocked')); } pause() {} },
        window: {},
        document: { getElementById: () => null, addEventListener() {} },
        fetch: () => Promise.resolve({ json: () => Promise.reject(new Error('nope')) }),
        setInterval: () => 1,
        setTimeout,
        clearTimeout,
        console
    };
    failing.globalThis = failing;
    vm.runInNewContext(source, failing, { filename: 'waiting.js' });

    await flush();
    assert.ok(true, 'no exception escaped the module');
});

(function () {
    'use strict';

    var API_BASE = '/api/queue';
    var body = document.body;
    var STAGE = body.getAttribute('data-stage') || 'consultation';
    var ALLOW_MOVE = body.getAttribute('data-allow-move') === 'true';
    var toastTimer = null;
    var visits = [];
    var inFlight = false;

    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    var ERROR_MESSAGES = {
        'Resolve the currently called visit before calling the next one.':
            'أنهِ الزيارة المستدعاة أولًا.',
        'No waiting visits in this queue.':
            'لا توجد زيارات في الانتظار.',
        'Another visit is already being called in this stage.':
            'توجد زيارة مستدعاة حاليًا في هذا الطابور.',
        'Only a called visit can be completed.':
            'يمكن إنهاء الزيارة المستدعاة فقط.',
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('btn-start').addEventListener('click', startQueue);
        document.getElementById('btn-next').addEventListener('click', callNext);
        refresh();
    });

    function current() {
        return visits.filter(function (v) { return v.status === 'called'; })[0] || null;
    }

    function waitingCount() {
        return visits.filter(function (v) { return v.status === 'waiting'; }).length;
    }

    function refresh() {
        return fetch(API_BASE + '/stages/' + STAGE + '/visits', { headers: headers })
            .then(function (r) {
                if (!r.ok) { throw new Error('تعذّر جلب الطابور.'); }
                return r.json();
            })
            .then(function (data) {
                visits = data.visits || [];
                render();
            })
            .catch(function (e) { showToast(e.message, 'error'); });
    }

    function render() {
        var tbody = document.getElementById('queue-tbody');
        tbody.innerHTML = '';

        if (visits.length === 0) {
            var empty = document.createElement('tr');
            empty.innerHTML = '<td class="empty-row" colspan="4">لا توجد زيارات في هذا الطابور.</td>';
            tbody.appendChild(empty);
        } else {
            visits.forEach(function (v) {
                var tr = document.createElement('tr');
                tr.setAttribute('data-visit-id', v.id);
                if (v.status === 'called') {
                    tr.className = 'row-current';
                }

                tr.appendChild(cell('cell-number', v.queue_number));

                var statusCell = document.createElement('td');
                var chip = document.createElement('span');
                chip.className = 'status-chip ' + (v.status_class || '');
                chip.textContent = v.status_label || v.status;
                statusCell.appendChild(chip);
                tr.appendChild(statusCell);

                tr.appendChild(cell('cell-time', v.registered_at));
                tr.appendChild(actionsCell(v));

                tbody.appendChild(tr);
            });
        }

        updateButtons();
    }

    function actionsCell(visit) {
        var actions = document.createElement('td');
        actions.className = 'cell-actions';

        if (visit.status !== 'called') {
            var none = document.createElement('span');
            none.className = 'no-actions';
            none.textContent = '—';
            actions.appendChild(none);
            return actions;
        }

        actions.appendChild(actionButton('إنهاء الزيارة', 'complete', 'btn-primary'));

        if (ALLOW_MOVE) {
            actions.appendChild(actionButton('نقل إلى النتيجة', 'move-to-review', ''));
        }

        return actions;
    }

    function cell(className, text) {
        var td = document.createElement('td');
        td.className = className;
        td.textContent = text == null ? '' : text;
        return td;
    }

    function actionButton(label, action, extraClass) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn ' + (extraClass || '');
        button.setAttribute('data-action', action);
        button.textContent = label;
        button.addEventListener('click', function () { runVisitAction(action); });
        return button;
    }

    function updateButtons() {
        var hasCurrent = current() !== null;
        var waiting = waitingCount();

        document.getElementById('btn-start').disabled = hasCurrent || waiting === 0;
        document.getElementById('btn-next').disabled = hasCurrent || waiting === 0;
    }

    function startQueue() {
        run(API_BASE + '/stages/' + STAGE + '/start', 'تعذّر بدء الطابور.');
    }

    function callNext() {
        run(API_BASE + '/stages/' + STAGE + '/call-next', 'تعذّر استدعاء التالي.');
    }

    function fetchNext() {
        return fetch(API_BASE + '/stages/' + STAGE + '/call-next', {
            method: 'POST',
            headers: headers,
        });
    }

    function run(url, fallbackMessage) {
        if (inFlight) { return; }
        inFlight = true;

        post(url, fallbackMessage)
            .then(function () { return refresh(); })
            .catch(function (e) { showToast(e.message, 'error'); })
            .finally(function () { inFlight = false; });
    }

    function runVisitAction(action) {
        var visit = current();
        if (!visit || inFlight) { return; }

        inFlight = true;

        post(API_BASE + '/visits/' + visit.id + '/' + action, 'تعذّر تنفيذ الإجراء.')
            .then(function () {
                if (action === 'complete' && waitingCount() > 0) {
                    return fetchNext().then(function () { return refresh(); });
                }
                return refresh();
            })
            .catch(function (e) { showToast(e.message, 'error'); })
            .finally(function () { inFlight = false; });
    }

    function post(url, fallbackMessage) {
        return fetch(url, { method: 'POST', headers: headers }).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (payload) {
                if (!r.ok) {
                    throw new Error(translateError(payload, fallbackMessage));
                }
                return payload;
            });
        });
    }

    function translateError(payload, fallbackMessage) {
        if (payload && payload.message && ERROR_MESSAGES[payload.message]) {
            return ERROR_MESSAGES[payload.message];
        }
        return fallbackMessage;
    }

    function showToast(message, type) {
        var toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast ' + (type === 'error' ? 'toast-error' : 'toast-success');
        toast.hidden = false;

        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.hidden = true; }, 4000);
    }
})();

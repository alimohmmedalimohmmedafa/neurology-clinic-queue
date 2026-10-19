(function () {
    'use strict';

    var API_BASE = '/api/queue';
    var inFlight = false;
    var toastTimer = null;

    var SUCCESS_MESSAGES = {
        call: function (visit) {
            return 'تم استدعاء الزيارة ' + visit.queue_number + '.';
        },
        complete: function (visit) {
            return 'تم إنهاء الزيارة ' + visit.queue_number + '.';
        },
        'move-to-review': function (visit) {
            return 'تم تحويل الزيارة ' + visit.queue_number + ' إلى طابور النتيجة.';
        },
        restore: function (visit) {
            return 'تمت إعادة الزيارة ' + visit.queue_number + ' إلى الانتظار.';
        },
    };

    var ERROR_MESSAGES = {
        'Only a called visit can be completed.':
            'يمكن إنهاء الزيارة المستدعاة فقط.',
        'Only a waiting, called or completed visit can be moved to review.':
            'يمكن تحويل زيارة منتظرة أو مستدعاة أو منتهية فقط إلى طابور النتيجة.',
        'Only today\'s visits can be moved to review.':
            'يمكن تحويل زيارات اليوم فقط.',
        'Visit is already in the review stage.':
            'الزيارة موجودة بالفعل في طابور النتيجة، لا يمكن تحويلها مرتين.',
        'Visit can no longer be moved to review.':
            'تعذّر تحويل الزيارة لأن حالتها تغيّرت.',
        'Only a waiting visit can be called.':
            'يمكن استدعاء الزيارة المنتظرة فقط.',
        'Another visit is already being called in this stage.':
            'توجد زيارة مستدعاة حاليًا في هذا الطابور، أنهِها أولًا.',
        'Visit is no longer waiting.':
            'الزيارة لم تعد في الانتظار.',
        'Only a skipped visit can be restored.':
            'يمكن إعادة الزيارة المتخطاة فقط إلى الانتظار.',
        'Visit can no longer be restored.':
            'تعذّرت إعادة الزيارة لأن حالتها تغيّرت.',
        'Only today\'s visits can be called.':
            'يمكن استدعاء زيارات اليوم فقط.',
        'Only today\'s visits can be restored.':
            'يمكن إعادة زيارات اليوم فقط.',
        'Resolve the currently called visit before calling the next one.':
            'أنهِ أو تخطَّ الزيارة المستدعاة أولًا.',
        'No waiting visits in this queue.':
            'لا توجد زوار في الانتظار.',
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('button[data-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                runAction(button);
            });
        });
    });

    function runAction(button) {
        if (inFlight) {
            return;
        }

        var row = button.closest('[data-visit-id]');
        var action = button.getAttribute('data-action');
        var actionType = button.getAttribute('data-action-type');

        if (!row || (!action && actionType !== 'print')) {
            return;
        }

        var visitId = row.getAttribute('data-visit-id');

        if (actionType === 'print') {
            inFlight = true;
            setBusy(true);

            // The reprint button stays locked until the print dialog closes,
            // so a repeated click cannot queue a second ticket.
            var released = false;

            function release() {
                if (released) {
                    return;
                }

                released = true;
                window.removeEventListener('afterprint', release);
                inFlight = false;
                setBusy(false);
            }

            loadReviewTicket(visitId)
                .then(function () {
                    window.addEventListener('afterprint', release);
                    setTimeout(release, 2000);
                    window.print();
                })
                .catch(function (error) {
                    showToast(error.message, 'error');
                    release();
                });

            return;
        }

        inFlight = true;
        setBusy(true);

        fetch(API_BASE + '/visits/' + visitId + '/' + action, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (body) {
                if (!response.ok) {
                    throw new Error(translateError(body, response.status));
                }
                return body;
            });
        }).then(function (visit) {
            var build = SUCCESS_MESSAGES[action];
            showToast(build ? build(visit) : 'تم تنفيذ الإجراء.', 'success');

            // Converting to the review queue prints the review ticket inside
            // this page and only then refreshes the table. The request stays
            // locked (inFlight) until the reload, so a repeated click can
            // never convert the same visit twice or print two tickets.
            if (action === 'move-to-review') {
                convertToReviewAndPrint(visitId);
                return;
            }

            window.setTimeout(function () { window.location.reload(); }, 900);
        }).catch(function (error) {
            showToast(error.message || 'تعذّر تنفيذ العملية.', 'error');
            inFlight = false;
            setBusy(false);
        });
    }

    function loadReviewTicket(visitId) {
        var printArea = document.getElementById('ticket-print');

        if (!printArea) {
            return Promise.reject(new Error('تعذّر تجهيز تذكرة النتيجة.'));
        }

        // Empty the previous template first: the print page always holds
        // exactly one ticket, and a failed load can never leave an old
        // number behind after the day's data has been cleared.
        printArea.innerHTML = '';

        return fetch('/tickets/visits/' + visitId + '?review=1', {
            headers: { 'Accept': 'text/html' },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('تعذّر تجهيز تذكرة النتيجة.');
            }
            return response.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var ticket = doc.querySelector('main.ticket');

            if (!ticket) {
                throw new Error('تعذّر تجهيز تذكرة النتيجة.');
            }

            printArea.innerHTML = ticket.outerHTML;
        });
    }

    function convertToReviewAndPrint(visitId) {
        var reloaded = false;

        function reload() {
            if (reloaded) {
                return;
            }

            reloaded = true;
            window.removeEventListener('afterprint', reload);
            window.location.reload();
        }

        loadReviewTicket(visitId).then(function () {
            // Refresh the table once the ticket is printed (or right after a
            // failed print) so the row shows the review stage and the visit
            // can be reprinted with the same number.
            window.addEventListener('afterprint', reload);
            setTimeout(reload, 2000);
            window.print();
        }).catch(function (error) {
            showToast(error.message, 'error');
            reload();
        });
    }

    function translateError(body, status) {
        if (body && body.message && ERROR_MESSAGES[body.message]) {
            return ERROR_MESSAGES[body.message];
        }
        if (body && body.message) {
            return body.message;
        }
        if (status === 404) {
            return 'الزيارة غير موجودة.';
        }
        return 'تعذّر تنفيذ العملية.';
    }

    function setBusy(busy) {
        document.querySelectorAll('button[data-action], button[data-action-type]').forEach(function (button) {
            button.disabled = busy;
        });
    }

    function showToast(message, type) {
        var toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast ' + (type === 'error'
            ? 'toast-error'
            : (type === 'info' ? 'toast-info' : 'toast-success'));
        toast.hidden = false;

        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toast.hidden = true;
        }, 4000);
    }
})();

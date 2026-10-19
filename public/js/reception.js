(function () {
    'use strict';

    var API_BASE = '/api/queue';

    var STAGE_LABELS = {
        consultation: 'المقابلة الأول',
        review: 'المراجعة',
    };

    var STATUS_LABELS = {
        waiting: 'منتظر',
        called: 'مستدعاة',
        completed: 'منتهية',
        skipped: 'تم التخطي',
    };

    var ERROR_MESSAGES = {
        'Resolve the currently called visit before calling the next one.':
            'أنهِ أو تخطَّ الزيارة المستدعاة أولًا.',
        'No waiting visits in this queue.':
            'لا توجد زوار في الانتظار.',
        'Only a called visit can be completed.':
            'يمكن إنهاء الزيارة المستدعاة فقط.',
        'Only a called visit can be skipped.':
            'يمكن تخطي الزيارة المستدعاة فقط.',
        'Only a waiting, called or completed visit can be moved to review.':
            'يمكن تحويل زيارة منتظرة أو مستدعاة أو منتهية فقط إلى طابور النتيجة.',
        'Only today\'s visits can be moved to review.':
            'يمكن تحويل زيارات اليوم فقط.',
        'Visit is already in the review stage.':
            'الزيارة موجودة بالفعل في طابور النتيجة.',
        'Visit can no longer be moved to review.':
            'تعذّر تحويل الزيارة لأن حالتها تغيّرت.',
    };

    // Cross-tab marker: written after a successful clear so the issue page
    // and any other tab can drop cached ticket templates they still hold.
    var CLEARED_AT_KEY = 'queue:cleared-at';

    var boards = {};
    var currentIds = { consultation: null, review: null };
    var inFlight = false;
    var toastTimer = null;
    var ticketWindows = [];

    document.addEventListener('DOMContentLoaded', function () {
        boards.consultation = document.querySelector('[data-stage="consultation"]');
        boards.review = document.querySelector('[data-stage="review"]');

        document.getElementById('btn-register').addEventListener('click', registerVisit);
        document.getElementById('btn-end-work').addEventListener('click', openEndWorkModal);
        document.getElementById('btn-end-work-cancel').addEventListener('click', closeEndWorkModal);
        document.getElementById('btn-end-work-confirm').addEventListener('click', performEndWork);

        document.getElementById('btn-clear-today').addEventListener('click', openClearTodayModal);
        document.getElementById('btn-clear-today-cancel').addEventListener('click', closeClearTodayModal);
        document.getElementById('btn-clear-today-confirm').addEventListener('click', performClearToday);

        document.getElementById('end-work-modal').addEventListener('click', function (event) {
            if (event.target.id === 'end-work-modal') {
                closeEndWorkModal();
            }
        });

        document.getElementById('clear-today-modal').addEventListener('click', function (event) {
            if (event.target.id === 'clear-today-modal') {
                closeClearTodayModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            if (!document.getElementById('end-work-modal').hidden) {
                closeEndWorkModal();
            }

            if (!document.getElementById('clear-today-modal').hidden) {
                closeClearTodayModal();
            }
        });

        ['consultation', 'review'].forEach(function (stage) {
            var board = boards[stage];

            board.querySelector('[data-action="start"]').addEventListener('click', function () {
                callStage(stage, 'start');
            });
            board.querySelector('[data-action="call-next"]').addEventListener('click', function () {
                callStage(stage, 'call-next');
            });
            board.querySelector('[data-action="complete"]').addEventListener('click', function () {
                visitAction(stage, 'complete', function (visit) {
                    return 'تم إنهاء الزيارة ' + visit.queue_number + '.';
                });
            });
            board.querySelector('[data-action="skip"]').addEventListener('click', function () {
                visitAction(stage, 'skip', function (visit) {
                    return 'تم تخطي الزيارة ' + visit.queue_number + ' لعدم الحضور.';
                });
            });
            board.querySelector('[data-action="move-to-review"]').addEventListener('click', function () {
                visitAction(stage, 'move-to-review', function (visit) {
                    return 'تم نقل الزيارة ' + visit.queue_number + ' إلى طابور النتيجة.';
                });
            });
        });

        refresh().catch(function (error) {
            showToast(error.message, 'error');
        });

        setInterval(function () {
            if (!inFlight) {
                refresh().catch(function () {});
            }
        }, 5000);
    });

    function api(url, options) {
        options = options || {};
        options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
        if (options.body) {
            options.headers['Content-Type'] = 'application/json';
        }

        return fetch(API_BASE + url, options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (body) {
                if (!response.ok) {
                    throw new Error(translateError(body, response.status));
                }
                return body;
            });
        }).catch(function (error) {
            if (error instanceof SyntaxError) {
                throw new Error('تعذّر الاتصال بالخادم.');
            }
            throw error;
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

    function refresh() {
        return api('/state').then(function (state) {
            document.getElementById('today-date').textContent = state.date;
            renderStage('consultation', state.stages.consultation);
            renderStage('review', state.stages.review);
        });
    }

    function renderStage(stage, data) {
        var board = boards[stage];
        currentIds[stage] = data.current ? data.current.id : null;

        var badge = board.querySelector('[data-field="badge"]');
        badge.textContent = 'يعمل';

        board.querySelector('[data-field="current-number"]').textContent =
            data.current ? data.current.queue_number : '—';

        board.querySelector('[data-field="current-status"]').textContent = data.current
            ? 'الزيارة ' + data.current.queue_number + ' مستدعاة الآن'
            : 'لا توجد زيارة مستدعاة';

        var list = board.querySelector('[data-field="waiting-list"]');
        list.textContent = '';

        if (data.waiting.length === 0) {
            var note = document.createElement('li');
            note.className = 'empty-note';
            note.textContent = 'لا توجد زوار في الانتظار';
            list.appendChild(note);
        } else {
            data.waiting.forEach(function (visit) {
                var item = document.createElement('li');

                var number = document.createElement('span');
                number.className = 'qnum';
                number.textContent = visit.queue_number;

                var status = document.createElement('span');
                status.className = 'qstatus';
                status.textContent = STATUS_LABELS[visit.status] || visit.status;

                item.appendChild(number);
                item.appendChild(status);
                list.appendChild(item);
            });
        }

        board.querySelector('[data-field="waiting-count"]').textContent = '(' + data.waiting.length + ')';

        var blocked = data.current !== null || data.waiting.length === 0;
        board.querySelector('[data-action="start"]').disabled = blocked;
        board.querySelector('[data-action="call-next"]').disabled = blocked;

        var hasCurrent = data.current !== null;
        board.querySelector('[data-action="complete"]').disabled = !hasCurrent;
        board.querySelector('[data-action="skip"]').disabled = !hasCurrent;
        board.querySelector('[data-action="move-to-review"]').disabled = !hasCurrent;
    }

    function registerVisit() {
        if (inFlight) {
            return;
        }

        run(
            function () { return api('/visits', { method: 'POST' }); },
            function (visit) {
                return 'تم تسجيل الزيارة رقم ' + visit.queue_number + ' في طابور ' + STAGE_LABELS.consultation + '.';
            },
            openTicketWindow()
        );
    }

    function openEndWorkModal() {
        document.getElementById('end-work-modal').hidden = false;
        document.getElementById('btn-end-work-cancel').focus();
    }

    function closeEndWorkModal() {
        document.getElementById('end-work-modal').hidden = true;
    }

    function performEndWork() {
        closeEndWorkModal();

        if (inFlight) {
            return;
        }

        inFlight = true;
        setBusy(true);

        api('/end-work', { method: 'POST' })
            .then(function (result) {
                if (result.already_ended) {
                    showToast('انتهت جلسة العمل مسبقًا. لا يوجد عمل نشط لإنهائه.', 'info');
                    return;
                }

                showToast(
                    'تم إنهاء العمل وإغلاق ' + result.closed_visits + ' زيارة غير مخدومة. ' +
                    'سيبدأ الترقيم من 001 في يوم العمل الجديد.',
                    'success'
                );
            })
            .catch(function (error) {
                showToast(error.message, 'error');
            })
            .then(function () {
                return refresh().catch(function () {});
            })
            .then(function () {
                inFlight = false;
                setBusy(false);
            });
    }

    function openClearTodayModal() {
        document.getElementById('clear-today-modal').hidden = false;
        document.getElementById('btn-clear-today-cancel').focus();
    }

    function closeClearTodayModal() {
        document.getElementById('clear-today-modal').hidden = true;
    }

    /**
     * Wipe today's data from the real state source (the API the boards, the
     * waiting screen and the printing all read from). Independent from the
     * end-work flow: it never starts or ends the work session.
     */
    function performClearToday() {
        closeClearTodayModal();

        if (inFlight) {
            return;
        }

        inFlight = true;
        setBusy(true);

        api('/clear-today', { method: 'POST' })
            .then(function (result) {
                // Mark the moment of the clear so every open tab can throw
                // away cached ticket templates before printing them again.
                try {
                    localStorage.setItem(CLEARED_AT_KEY, String(Date.now()));
                } catch (error) {
                    // Storage may be blocked; the boards themselves stay safe.
                }

                closeTicketWindows();

                showToast(
                    'تم مسح جميع بيانات اليوم (' + result.deleted_visits + ' تذكرة). ' +
                    'يبدأ الترقيم الجديد من 001 وتذكرة الفحص من F-001.',
                    'success'
                );
            })
            .catch(function (error) {
                showToast(error.message, 'error');
            })
            .then(function () {
                return refresh().catch(function () {});
            })
            .then(function () {
                inFlight = false;
                setBusy(false);
            });
    }

    // Old tickets must never be re-printed after a clear: close every ticket
    // window this page has opened for today.
    function closeTicketWindows() {
        ticketWindows.forEach(function (ticketWindow) {
            if (ticketWindow && !ticketWindow.closed) {
                ticketWindow.close();
            }
        });

        ticketWindows = [];
    }

    function callStage(stage, action) {
        run(
            function () { return api('/stages/' + stage + '/' + action, { method: 'POST' }); },
            function (visit) {
                return 'تم استدعاء الزيارة ' + visit.queue_number + ' في طابور ' + STAGE_LABELS[stage] + '.';
            }
        );
    }

    function visitAction(stage, action, messageBuilder) {
        var visitId = currentIds[stage];
        if (!visitId) {
            showToast('لا توجد زيارة حالية في هذا الطابور.', 'error');
            return;
        }

        var ticketWindow = action === 'move-to-review' && !inFlight ? openTicketWindow() : null;

        run(
            function () { return api('/visits/' + visitId + '/' + action, { method: 'POST' }); },
            messageBuilder,
            ticketWindow
        );
    }

    function run(request, messageBuilder, ticketWindow) {
        if (inFlight) {
            if (ticketWindow && !ticketWindow.closed) {
                ticketWindow.close();
            }
            return;
        }

        inFlight = true;
        setBusy(true);

        request()
            .then(function (result) {
                var message = messageBuilder(result);

                if (ticketWindow && !showTicket(ticketWindow, result)) {
                    message += ' — تعذّر فتح نافذة التذكرة، اسمح بالنوافذ المنبثقة.';
                }

                showToast(message, 'success');
            })
            .catch(function (error) {
                if (ticketWindow && !ticketWindow.closed) {
                    ticketWindow.close();
                }
                showToast(error.message, 'error');
            })
            .then(function () {
                return refresh().catch(function () {});
            })
            .then(function () {
                inFlight = false;
                setBusy(false);
            });
    }

    function openTicketWindow() {
        var ticketWindow = window.open('', '_blank', 'width=460,height=640');

        if (ticketWindow) {
            ticketWindows.push(ticketWindow);
        }

        return ticketWindow;
    }

    function showTicket(ticketWindow, visit) {
        if (!ticketWindow || ticketWindow.closed || !visit || !visit.id) {
            return false;
        }

        ticketWindow.location = '/tickets/visits/' + visit.id + '?auto=1';

        return true;
    }

    function setBusy(busy) {
        document.getElementById('btn-register').disabled = busy;
        document.getElementById('btn-end-work').disabled = busy;
        document.getElementById('btn-clear-today').disabled = busy;

        if (busy) {
            document.querySelectorAll('button[data-action]').forEach(function (button) {
                button.disabled = true;
            });
        }
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

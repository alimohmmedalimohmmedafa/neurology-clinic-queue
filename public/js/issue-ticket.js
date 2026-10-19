(function () {
    'use strict';

    // Written by the reception page after a successful "clear today"; any
    // ticket cached before that moment must never be printed again.
    var CLEARED_AT_KEY = 'queue:cleared-at';

    var inFlight = false;
    var issuedTicket = null;
    var issuedAt = null;
    var toastTimer = null;

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('btn-issue');
        var reprintButton = document.getElementById('btn-reprint');
        var printArea = document.getElementById('ticket-print');
        var toast = document.getElementById('toast');

        function ticketIsStale() {
            if (!issuedTicket || issuedAt === null) {
                return false;
            }

            try {
                var clearedAt = parseInt(localStorage.getItem(CLEARED_AT_KEY) || '0', 10);

                return clearedAt > issuedAt;
            } catch (error) {
                return false;
            }
        }

        function dropIssuedTicket() {
            issuedTicket = null;
            issuedAt = null;
            printArea.innerHTML = '';
            reprintButton.hidden = true;
        }

        button.addEventListener('click', function () {
            if (inFlight) {
                return;
            }

            if (ticketIsStale()) {
                dropIssuedTicket();
            }

            inFlight = true;
            button.disabled = true;

            fetch('/api/queue/visits', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (body) {
                    if (!response.ok) {
                        throw new Error((body && body.message) || 'تعذّر إصدار الرقم.');
                    }
                    return body;
                });
            }).then(function (visit) {
                return loadTicket(visit.id);
            }).then(function (ticketHtml) {
                issuedTicket = ticketHtml;
                issuedAt = Date.now();
                reprintButton.hidden = false;
                window.print();
            }).catch(function (error) {
                showToast(error.message || 'تعذّر إصدار الرقم.');
            }).then(function () {
                inFlight = false;
                button.disabled = false;
            });
        });

        reprintButton.addEventListener('click', function () {
            if (inFlight || !issuedTicket) {
                return;
            }

            if (ticketIsStale()) {
                dropIssuedTicket();
                showToast('تم مسح بيانات اليوم؛ أصدر رقمًا جديدًا للطباعة.');
                return;
            }

            window.print();
        });

        window.addEventListener('pageshow', function () {
            inFlight = false;
            button.disabled = false;
            toast.hidden = true;

            if (ticketIsStale()) {
                dropIssuedTicket();
            }
        });

        function loadTicket(visitId) {
            // Empty the previous template before loading the new one: the
            // print page holds exactly one ticket at a time, and a failed
            // load can never leave an old ticket number behind.
            printArea.innerHTML = '';

            return fetch('/tickets/visits/' + visitId).then(function (response) {
                if (!response.ok) {
                    throw new Error('تعذّر تجهيز التذكرة.');
                }

                return response.text();
            }).then(function (html) {
                var ticket = new DOMParser()
                    .parseFromString(html, 'text/html')
                    .querySelector('.ticket');

                if (!ticket) {
                    throw new Error('تعذّر تجهيز التذكرة.');
                }

                printArea.innerHTML = ticket.outerHTML;

                return printArea.innerHTML;
            });
        }

        function showToast(message) {
            toast.textContent = message;
            toast.className = 'toast toast-error';
            toast.hidden = false;

            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () {
                toast.hidden = true;
            }, 4000);
        }
    });
})();

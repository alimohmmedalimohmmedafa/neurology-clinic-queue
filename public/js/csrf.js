(function () {
    'use strict';

    // Attach the Laravel CSRF token to every state-changing fetch and send the
    // staff member back to the login page if the session has expired.
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';
    var originalFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
        init = init || {};

        var method = (init.method || (input && input.method) || 'GET').toUpperCase();

        if (token && method !== 'GET' && method !== 'HEAD') {
            var headers = new Headers(init.headers || {});
            if (!headers.has('X-CSRF-TOKEN')) {
                headers.set('X-CSRF-TOKEN', token);
            }
            init.headers = headers;
        }

        return originalFetch(input, init).then(function (response) {
            if (response.status === 401 && window.location.pathname.indexOf('/login') !== 0) {
                window.location = '/login';
            }

            return response;
        });
    };
})();

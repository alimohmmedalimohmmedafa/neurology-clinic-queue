<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>شاشة العرض — نظام الطابور</title>
    <link rel="stylesheet" href="{{ asset('css/waiting.css') }}">
</head>
<body>
    <div id="audio-hint" class="audio-hint">انقر في أي مكان لتفعيل التنبيه الصوتي</div>

    <div class="audio-tools">
        <button id="btn-test-audio" type="button" class="audio-test-btn">اختبار الصوت</button>
    </div>

    <main class="stage-screen">
        <section id="idle-panel" class="panel" hidden>
            <p class="message">بانتظار أول استدعاء</p>
        </section>

        <section id="paused-panel" class="panel" hidden>
            <p class="message paused-message">الانتظار مؤقتًا</p>
        </section>

        <section id="call-panel" class="panel" hidden>
            <div id="service-label" class="service-label"></div>
            <div id="queue-number" class="queue-number">—</div>
            <div class="please-enter">الرجاء الدخول</div>
        </section>
    </main>

    <script src="{{ asset('js/waiting.js') }}"></script>
</body>
</html>

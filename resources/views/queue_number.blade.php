<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>إصدار رقم انتظار — نظام الطابور</title>
    <link rel="stylesheet" href="{{ asset('css/reception.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ticket.css') }}">
</head>
<body class="issue-page">
    <div class="app-shell">
        @include('partials.sidebar', ['active' => 'issue'])

        <div class="app-body">
            <header class="topbar">
                <div class="topbar-title">
                    <h1>إصدار رقم انتظار</h1>
                    <span class="today">{{ now()->toDateString() }}</span>
                </div>
            </header>

            <div id="toast" class="toast" hidden></div>

            <main class="issue-wrap">
                <section class="issue-card">
                    <p class="issue-lead">
                        اضغط على الزر لتسجيل زيارة جديدة لليوم الحالي ومنحها رقم انتظار فريدًا
                        في طابور المقابلة.
                    </p>
                    <p class="issue-note">
                        التذكرة تحتوي على رقم الانتظار وتاريخ الزيارة فقط، دون اسم أو هاتف أو رسوم.
                    </p>
                    <div class="issue-actions">
                        <button id="btn-issue" class="btn btn-primary btn-lg" type="button">إصدار رقم جديد</button>
                        <button id="btn-reprint" class="print-btn" type="button" hidden>طباعة التذكرة</button>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div class="ticket-print" id="ticket-print"></div>

    <script src="{{ asset('js/csrf.js') }}"></script>
    <script src="{{ asset('js/issue-ticket.js') }}"></script>
</body>
</html>

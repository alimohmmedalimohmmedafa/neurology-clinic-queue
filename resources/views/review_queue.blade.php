<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>طابور النتيجة — نظام الطابور</title>
    <link rel="stylesheet" href="{{ asset('css/reception.css') }}">
    <link rel="stylesheet" href="{{ asset('css/today-patients.css') }}">
</head>
<body data-stage="review" data-allow-move="false">
    <div class="app-shell">
        @include('partials.sidebar', ['active' => 'review'])

        <div class="app-body">
            <header class="topbar">
                <div class="topbar-title">
                    <h1>طابور النتيجة</h1>
                    <span class="today">{{ now()->toDateString() }}</span>
                </div>
                <div class="topbar-actions">
                    <button id="btn-start" class="btn btn-primary btn-lg">بدء الطابور</button>
                    <button id="btn-next" class="btn btn-lg" disabled>استدعاء التالي</button>
                </div>
            </header>

            <div id="toast" class="toast" hidden></div>

            <main class="page">
                <section class="table-card">
                    <div class="table-meta">
                        <span>طابور النتيجة</span>
                    </div>

                    <table class="patients-table">
                        <thead>
                            <tr>
                                <th>رقم الانتظار</th>
                                <th>الحالة</th>
                                <th>وقت التسجيل</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody id="queue-tbody">
                            <tr>
                                <td class="empty-row" colspan="4">جارٍ التحميل…</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </main>
        </div>
    </div>

    <script src="{{ asset('js/csrf.js') }}"></script>
    <script src="{{ asset('js/stage-queue.js') }}"></script>
</body>
</html>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>استقبال — نظام الطابور</title>
    <link rel="stylesheet" href="{{ asset('css/reception.css') }}">
</head>
<body>
    <div class="app-shell">
        @include('partials.sidebar', ['active' => 'home'])

        <div class="app-body">
            <header class="topbar">
                <div class="topbar-title">
                    <h1>نظام الطابور — موظف الاستقبال</h1>
                    <span id="today-date" class="today"></span>
                </div>
                <div class="topbar-actions">
                    <button id="btn-register" class="btn btn-primary btn-lg">تسجيل زيارة جديدة</button>
                    <button id="btn-end-work" class="btn btn-end-work btn-lg">إنهاء العمل</button>
                    <button id="btn-clear-today" class="btn btn-clear-today btn-lg">مسح بيانات اليوم</button>
                </div>
            </header>

            <div id="toast" class="toast" hidden></div>

            <main class="boards">

        <section class="board board-consultation" data-stage="consultation">
            <header class="board-header">
                <h2>طابور المقابلة الأول</h2>
                <span class="badge badge-ok" data-field="badge">يعمل</span>
            </header>

            <div class="current-box">
                <div class="current-label">الزيارة الحالية</div>
                <div class="current-number" data-field="current-number">—</div>
                <div class="current-status" data-field="current-status">لا توجد زيارة مستدعاة</div>
            </div>

            <div class="actions-row">
                <button class="btn" data-action="start">بدء الطابور</button>
                <button class="btn" data-action="call-next">استدعاء التالي</button>
            </div>

            <div class="visit-actions">
                <button class="btn btn-success" data-action="complete">إنهاء الزيارة</button>
                <button class="btn btn-danger" data-action="skip">تخطي (عدم حضور)</button>
                <button class="btn btn-info" data-action="move-to-review">نقل إلى المراجعة</button>
            </div>

            <div class="waiting-box">
                <h3>في الانتظار <span class="waiting-count" data-field="waiting-count">(0)</span></h3>
                <ul class="waiting-list" data-field="waiting-list"></ul>
            </div>
        </section>

        <section class="board board-review" data-stage="review">
            <header class="board-header">
                <h2>طابور النتيجة</h2>
                <span class="badge badge-ok" data-field="badge">يعمل</span>
            </header>

            <div class="current-box">
                <div class="current-label">الزيارة الحالية</div>
                <div class="current-number" data-field="current-number">—</div>
                <div class="current-status" data-field="current-status">لا توجد زيارة مستدعاة</div>
            </div>

            <div class="actions-row">
                <button class="btn" data-action="start">بدء الطابور</button>
                <button class="btn" data-action="call-next">استدعاء التالي</button>
            </div>

            <div class="visit-actions">
                <button class="btn btn-success" data-action="complete">إنهاء الزيارة</button>
                <button class="btn btn-danger" data-action="skip">تخطي (عدم حضور)</button>
                <button class="btn btn-info" data-action="move-to-review" hidden>نقل إلى المراجعة</button>
            </div>

            <div class="waiting-box">
                <h3>في الانتظار <span class="waiting-count" data-field="waiting-count">(0)</span></h3>
                <ul class="waiting-list" data-field="waiting-list"></ul>
            </div>
        </section>

    </main>

    <div id="end-work-modal" class="modal-overlay" hidden>
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="end-work-title">
            <h2 id="end-work-title">إنهاء العمل</h2>
            <p class="modal-lead">أنت على وشك إنهاء جلسة العمل الحالية. سيؤدي التأكيد إلى:</p>
            <ul class="modal-list">
                <li>إنهاء أي زيارة مستدعاة حاليًا وإخلاء طابوري المقابلة والمراجعة.</li>
                <li>إغلاق الزوار غير المخدومين، مع الاحتفاظ بسجلاتهم وأحداثهم كاملة.</li>
                <li>مسح الرقم الظاهر على شاشة الانتظار لتعود إلى حالة الاستعداد.</li>
                <li>بدء العمل من الرقم 001 في يوم العمل الجديد.</li>
            </ul>
            <p class="modal-note">لا يمكن التراجع عن هذه العملية، ولن تُحذف أي سجلات.</p>
            <div class="modal-actions">
                <button type="button" id="btn-end-work-cancel" class="btn">تراجع</button>
                <button type="button" id="btn-end-work-confirm" class="btn btn-end-work">تأكيد إنهاء العمل</button>
            </div>
        </div>
    </div>

    <div id="clear-today-modal" class="modal-overlay" hidden>
        <div class="modal-panel modal-panel-clear" role="dialog" aria-modal="true" aria-labelledby="clear-today-title">
            <h2 id="clear-today-title">مسح بيانات اليوم</h2>
            <p class="modal-lead">هل أنت متأكد من مسح جميع بيانات اليوم؟ سيتم حذف جميع التذاكر والمرضى والطلبات والعدادات الحالية من ذاكرة النظام، ولا يمكن استعادتها بعد التأكيد.</p>
            <p class="modal-note">بعد التأكيد يبدأ ترقيم الكشف من 001 وتذكرة الفحص من F-001، ولا يمكن التراجع عن هذه العملية.</p>
            <div class="modal-actions">
                <button type="button" id="btn-clear-today-cancel" class="btn">إلغاء</button>
                <button type="button" id="btn-clear-today-confirm" class="btn btn-clear-today">تأكيد المسح</button>
            </div>
        </div>
    </div>

        </div>
    </div>

    <script src="{{ asset('js/csrf.js') }}"></script>
    <script src="{{ asset('js/reception.js') }}"></script>
</body>
</html>

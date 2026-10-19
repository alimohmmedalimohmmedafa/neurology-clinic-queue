<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>جميع مرضى اليوم — نظام الطابور</title>
    <link rel="stylesheet" href="{{ asset('css/reception.css') }}">
    <link rel="stylesheet" href="{{ asset('css/today-patients.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ticket.css') }}">
</head>
<body class="today-page">
    <div class="app-shell">
        @include('partials.sidebar', ['active' => 'today'])

        <div class="app-body">
            <header class="topbar">
                <div class="topbar-title">
                    <h1>جميع مرضى اليوم</h1>
                    <span class="today">{{ $date }}</span>
                </div>
            </header>

            <div id="toast" class="toast" hidden></div>

            <main class="page">
        <form class="filters" method="get" action="{{ route('patients.today') }}">
            <input class="filters-search" type="search" name="search" value="{{ $search }}"
                   placeholder="بحث برقم المريض" aria-label="بحث برقم المريض">
            <select class="filters-select" name="stage" aria-label="تصفية حسب المرحلة">
                <option value="">كل المراحل</option>
                <option value="consultation" @selected($stage === \App\Enums\VisitStage::Consultation)>المقابلة</option>
                <option value="review" @selected($stage === \App\Enums\VisitStage::Review)>النتيجة</option>
            </select>
            <select class="filters-select" name="status" aria-label="تصفية حسب الحالة">
                <option value="">كل الحالات</option>
                <option value="waiting" @selected($status === \App\Enums\VisitStatus::Waiting)>منتظر</option>
                <option value="called" @selected($status === \App\Enums\VisitStatus::Called)>تم استدعاؤه</option>
                <option value="skipped" @selected($status === \App\Enums\VisitStatus::Skipped)>تم تجاوزه</option>
                <option value="completed" @selected($status === \App\Enums\VisitStatus::Completed)>مكتملة</option>
            </select>
            <button class="btn btn-primary" type="submit">تصفية</button>
            @if ($search !== '' || $stage !== null || $status !== null)
                <a class="btn" href="{{ route('patients.today') }}">إظهار الكل</a>
            @endif
        </form>

        <section class="table-card">
            <div class="table-meta">
                <span>عدد الزيارات: {{ $rows->count() }}</span>
            </div>

            <table class="patients-table">
                <thead>
                    <tr>
                        <th>رقم المريض</th>
                        <th>المرحلة</th>
                        <th>الحالة</th>
                        <th>وقت التسجيل</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr data-visit-id="{{ $row['id'] }}">
                            <td class="cell-number">{{ $row['number'] }}</td>
                            <td>{{ $row['stage_label'] }}</td>
                            <td>
                                <span class="status-chip {{ $row['status_class'] }}">{{ $row['status_label'] }}</span>
                            </td>
                            <td class="cell-time">{{ $row['registered_at'] }}</td>
                            <td class="cell-actions">
                                @if ($row['actions'] === [])
                                    <span class="no-actions">لا توجد إجراءات</span>
                                @else
                                    @foreach ($row['actions'] as $action)
                                        <button type="button" class="btn {{ $action['class'] }}"
                                                @if (!empty($action['endpoint'])) data-action="{{ $action['endpoint'] }}" @endif
                                                @if (!empty($action['type'])) data-action-type="{{ $action['type'] }}" @endif>
                                            {{ $action['label'] }}
                                        </button>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty-row" colspan="5">لا توجد زيارات مطابقة.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </main>

        </div>
    </div>

    <div class="ticket-print" id="ticket-print"></div>

    <script src="{{ asset('js/csrf.js') }}"></script>
    <script src="{{ asset('js/today-patients.js') }}"></script>
</body>
</html>

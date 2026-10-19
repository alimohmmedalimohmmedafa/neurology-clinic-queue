<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تذكرة الزيارة {{ $visit->formattedTicketNumber() }}</title>
    <link rel="stylesheet" href="{{ asset('css/ticket.css') }}">
</head>
<body class="ticket-page">
    <main class="ticket">
        <h1 class="clinic-name">{{ config('app.name') }}</h1>

        @if (request()->query('review') === '1')
            <div class="service">النتيجة</div>
        @else
            <div class="service">
                {{ $visit->stage === \App\Enums\VisitStage::Review ? 'مراجعة الطبيب بعد النتيجة' : 'المقابلة الأول' }}
            </div>
        @endif

        <div class="queue-number">{{ $visit->formattedTicketNumber() }}</div>

        <div class="meta">
            <div>التاريخ: {{ $visit->created_at->format('Y-m-d') }}</div>
            <div>الوقت: {{ $visit->created_at->format('H:i') }}</div>
        </div>
    </main>

    <button class="print-btn" type="button" onclick="window.print()">طباعة التذكرة</button>

    @if (request()->query('auto') === '1')
        <script>
            window.addEventListener('load', function () { window.print(); });
        </script>
    @endif
</body>
</html>

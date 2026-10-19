<aside class="sidebar">
    <a class="sidebar-brand" href="{{ route('reception', [], false) }}">نظام الطابور</a>
    <nav class="sidebar-nav">
        <a class="{{ 'sidebar-link' . ($active === 'issue' ? ' active' : '') }}" href="{{ route('queue-number', [], false) }}"{!! $active === 'issue' ? ' aria-current="page"' : '' !!}>إصدار رقم انتظار</a>
        <a class="{{ 'sidebar-link' . ($active === 'consultation' ? ' active' : '') }}" href="{{ route('consultation-queue', [], false) }}"{!! $active === 'consultation' ? ' aria-current="page"' : '' !!}>طابور المقابلة</a>
        <a class="{{ 'sidebar-link' . ($active === 'review' ? ' active' : '') }}" href="{{ route('review-queue', [], false) }}"{!! $active === 'review' ? ' aria-current="page"' : '' !!}>طابور النتيجة</a>
        <a class="{{ 'sidebar-link' . ($active === 'today' ? ' active' : '') }}" href="{{ route('patients.today', [], false) }}"{!! $active === 'today' ? ' aria-current="page"' : '' !!}>جميع مرضى اليوم</a>
        <a class="{{ 'sidebar-link' . ($active === 'display' ? ' active' : '') }}" href="{{ route('waiting', [], false) }}" target="_blank" rel="noopener"{!! $active === 'display' ? ' aria-current="page"' : '' !!}>شاشة العرض</a>
        <a class="{{ 'sidebar-link' . ($active === 'home' ? ' active' : '') }}" href="{{ route('reception', [], false) }}"{!! $active === 'home' ? ' aria-current="page"' : '' !!}>الاستقبال</a>
    </nav>

    <form class="sidebar-footer" method="POST" action="{{ route('logout', [], false) }}">
        @csrf
        <button type="submit" class="sidebar-logout-btn">تسجيل الخروج</button>
    </form>
</aside>


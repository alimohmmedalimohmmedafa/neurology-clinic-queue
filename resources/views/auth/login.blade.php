<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول — نظام الطابور</title>
    <link rel="stylesheet" href="{{ asset('css/reception.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body class="auth-page">
    <main class="auth-card">
        <div class="auth-brand">
            <h1>تسجيل الدخول</h1>
            <p class="auth-clinic">{{ config('app.name') }}</p>
        </div>

        @if ($errors->any())
            <div class="auth-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.attempt', [], false) }}" class="auth-form" autocomplete="off">
            @csrf

            <label class="auth-field">
                <span>اسم المستخدم</span>
                <input type="text" name="username" value="{{ old('username') }}" required autofocus
                       autocomplete="username" spellcheck="false">
            </label>

            <label class="auth-field">
                <span>كلمة المرور</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>

            <button type="submit" class="btn btn-primary btn-lg auth-submit">تسجيل الدخول</button>
        </form>
    </main>
</body>
</html>

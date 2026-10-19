@echo off
set "PATH=%SystemRoot%\System32;%SystemRoot%;%PATH%"
chcp 65001 >nul
setlocal EnableExtensions
title تشغيل العيادة

rem ==========================================================
rem  ملف تشغيل النظام بنقرة واحدة (Windows + Laravel)
rem  لا يعدّل أي ملفات داخل المشروع ولا قاعدة البيانات.
rem ==========================================================

set "PROJECT_DIR=C:\Users\Ali\project-depend\shipment_pp_backup"
set "PHP_EXE=C:\xampp\php\php.exe"
set "HOST=127.0.0.1"
set "PORT=8000"
set "SITE_URL=http://127.0.0.1:8000"
set "MAX_WAIT=60"

echo.
echo ==================================================
echo          تشغيل العيادة بنقرة واحدة
echo ==================================================
echo.

rem --- (1) التحقق من مجلد المشروع ---
if not exist "%PROJECT_DIR%\artisan" (
    echo [خطأ] مجلد المشروع غير صحيح او ان ملف artisan غير موجود:
    echo        %PROJECT_DIR%
    echo.
    echo        تأكد من وضع هذا الملف داخل مجلد مشروع Laravel الصحيح.
    echo.
    pause
    exit /b 1
)

rem --- (2) التحقق من وجود ملف PHP ---
if not exist "%PHP_EXE%" (
    echo [خطأ] لم يتم العثور على ملف PHP في المسار المحدد:
    echo        %PHP_EXE%
    echo.
    echo        تأكد من تثبيت XAMPP ومن صحة مسار PHP.
    echo.
    pause
    exit /b 1
)

rem --- (3) الانتقال إلى مجلد المشروع ---
cd /d "%PROJECT_DIR%"
if errorlevel 1 (
    echo [خطأ] تعذر الدخول الى مجلد المشروع:
    echo        %PROJECT_DIR%
    echo.
    pause
    exit /b 1
)

echo [OK] مجلد المشروع : %PROJECT_DIR%
echo [OK] ملف PHP      : %PHP_EXE%
echo.

rem --- (4) هل الموقع يعمل بالفعل؟ -> لا تشغّل نسخة اخرى ---
call :http_ready
if not errorlevel 1 (
    echo [OK] الخادم يعمل بالفعل على المنفذ %PORT% - سيتم فتح الموقع مباشرة.
    echo.
    start "" "%SITE_URL%"
    exit /b 0
)

rem --- (5) هل يوجد برنامج يشغل المنفذ بدون موقع جاهز؟ ---
call :port_open
if not errorlevel 1 (
    echo [خطأ] المنفذ %PORT% مشغول ببرنامج آخر, لكن الموقع لا يستجيب.
    echo.
    echo        * توقفت العملية ولن يتم اغلاق او ايقاف اي برنامج آخر.
    echo        * اغلق البرنامج المستخدم للمنفذ %PORT% ثم اعد تشغيل هذا الملف.
    echo        * لم يتم تغيير المنفذ تلقائياً.
    echo.
    pause
    exit /b 2
)

rem --- (6) تشغيل خادم Laravel في نافذة منفصلة مصغّرة ---
echo [..] جارٍ تشغيل خادم Laravel في نافذة منفصلة مصغّرة ...
start "Laravel Server - port %PORT%" /min "%PHP_EXE%" artisan serve --host=%HOST% --port=%PORT%

rem --- (7) انتظار جاهزية الموقع فعلياً (فحص مستمر + مهلة قصوى) ---
call :now_seconds
set /a "start_sec=elapsed_now"

:wait_loop
call :now_seconds
set /a "elapsed=elapsed_now-start_sec"
if %elapsed% lss 0 set /a "elapsed+=86400"
if %elapsed% geq %MAX_WAIT% goto timeout_msg
call :http_ready
if not errorlevel 1 goto site_ready
<nul set /p =.
ping -n 2 127.0.0.1 >nul
goto wait_loop

:timeout_msg
echo.
echo.
echo [خطأ] انتهت المهلة القصوى (%MAX_WAIT% ثانية) ولم يصبح الموقع جاهزاً.
echo        لم يتم فتح المتصفح قبل التأكد من عمل الموقع.
echo.
call :port_open
if not errorlevel 1 (
    echo        السبب المحتمل: الخادم يعمل لكنه لم يستجب على %SITE_URL%
    echo        راجع نافذة الخادم المصغّرة لمعرفة تفاصيل الخطأ.
) else (
    echo        السبب المحتمل: لم يبدأ الخادم على المنفذ %PORT% ابداً.
    echo        راجع نافذة الخادم المصغّرة لمعرفة تفاصيل الخطأ.
)
echo.
pause
exit /b 3

:site_ready
call :now_seconds
set /a "elapsed=elapsed_now-start_sec"
if %elapsed% lss 0 set /a "elapsed+=86400"
echo.
echo.
echo [OK] الموقع جاهز خلال %elapsed% ثانية.
echo [..] جارٍ فتح المتصفح ...
echo.
start "" "%SITE_URL%"
exit /b 0

rem ==========================================================
rem  دوال مساعدة لفحص الجاهزية الحقيقية للموقع
rem ==========================================================

:http_ready
"%PHP_EXE%" -r "$o=@file_get_contents('http://127.0.0.1:%PORT%/',false,stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0]]));exit($o===false?1:0);" >nul 2>&1
exit /b %errorlevel%

:now_seconds
set "NOW=%TIME: =0%"
for /f "tokens=1-3 delims=:.," %%a in ("%NOW%") do set /a "elapsed_now=(((1%%a-100)*60+(1%%b-100))*60+(1%%c-100))"
exit /b

:port_open
"%PHP_EXE%" -r "$c=@fsockopen('127.0.0.1',%PORT%,$e,$s,1);if($c){fclose($c);exit(0);}exit(1);" >nul 2>&1
exit /b %errorlevel%

@echo off
setlocal EnableDelayedExpansion

REM Manual override: start.bat 8
if not "%~1"=="" (
    set WORKERS=%~1
    goto start_services
)

REM workers = min(cores*2, free_mb/64), clamp [2, 32]
set CORES=%NUMBER_OF_PROCESSORS%
if "%CORES%"=="" set CORES=2
if %CORES% lss 1 set CORES=2

set FREE_KB=0
for /f %%A in ('powershell -NoProfile -Command "(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory" 2^>nul') do set FREE_KB=%%A
if "%FREE_KB%"=="" set FREE_KB=0

set /a CPU_WORKERS=CORES*2
set /a MEM_WORKERS=999
if %FREE_KB% gtr 0 (
    set /a FREE_MB=FREE_KB/1024
    set /a MEM_WORKERS=FREE_MB/64
)

if %MEM_WORKERS% lss %CPU_WORKERS% (
    set WORKERS=%MEM_WORKERS%
) else (
    set WORKERS=%CPU_WORKERS%
)

if %WORKERS% lss 2 set WORKERS=2
if %WORKERS% gtr 32 set WORKERS=32

echo Auto workers: %WORKERS% ^(cores=%CORES%, free=%FREE_KB% KB, rule=min(cores*2, free_mb/64)^)

:start_services
if not exist logs mkdir logs

REM Persist HTTP listen port in port.txt (1000-10000). Never default to 80.
set PORT=
if exist port.txt (
    set /p PORT=<port.txt
)
if defined PORT (
    echo !PORT!| findstr /r "^[1-9][0-9]*$" >nul
    if errorlevel 1 set PORT=
)
if defined PORT (
    if !PORT! lss 1000 set PORT=
)
if defined PORT (
    if !PORT! gtr 10000 set PORT=
)
if not defined PORT (
    set /a PORT=1000+%RANDOM%%%9001
    >port.txt echo !PORT!
    echo Selected HTTP port: !PORT! ^(saved to port.txt^)
) else (
    echo Using saved HTTP port: !PORT! ^(port.txt^)
)

echo Starting PHP FastCGI Pool via xxfpm (%WORKERS% workers)...
REM Use a separate process group window title; xxfpm calls FreeConsole() so
REM closing this bat window will not kill php-cgi workers.
start "xxfpm" core\xxfpm\bin\xxfpm.exe "core\php\php-cgi.exe -c core\php\php.ini" -n %WORKERS% -i 127.0.0.1 -p 9000

echo Starting Nginx on port !PORT!...
set "PROJECT_ROOT=%~dp0"
set "PROJECT_ROOT=%PROJECT_ROOT:\=/%"
>listen.conf echo listen !PORT!^;
>public.conf echo root "%PROJECT_ROOT%www/public"^;
>>public.conf echo index index.php index.html index.htm^;
cd core\nginx
start "nginx" nginx.exe
cd ..\..

echo.
echo Waiting for services to start...
timeout /t 2 > nul

echo.
echo === Nginx Status ===
tasklist /fi "imagename eq nginx.exe" | findstr "nginx.exe"
if errorlevel 1 (
    echo [ERROR] Nginx is not running!
) else (
    echo [OK] Nginx is running on http://127.0.0.1:!PORT!/
)

echo.
echo === xxfpm Status ===
tasklist /fi "imagename eq xxfpm.exe" | findstr "xxfpm.exe"
if errorlevel 1 (
    echo [ERROR] xxfpm is not running!
) else (
    echo [OK] xxfpm is running.
)

echo.
echo === PHP Status ===
tasklist /fi "imagename eq php-cgi.exe" | findstr "php-cgi.exe"
if errorlevel 1 (
    echo [ERROR] PHP FastCGI is not running!
) else (
    echo [OK] PHP FastCGI is running.
)

echo.
echo Opening http://127.0.0.1:!PORT!/ ...
start http://127.0.0.1:!PORT!/

echo.
echo Services keep running after this window closes. Use stop.bat to shut down.
echo Press ENTER to close this window.
pause > nul
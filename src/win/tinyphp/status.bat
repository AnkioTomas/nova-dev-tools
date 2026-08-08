@echo off
setlocal EnableDelayedExpansion

echo.
echo === HTTP Port ===
if not exist port.txt goto no_port
set PORT=
set /p PORT=<port.txt
if not defined PORT goto no_port
echo Port: !PORT!
echo URL:  http://127.0.0.1:!PORT!/
echo.
goto show_procs

:no_port
echo Not configured yet. Run start.bat first.
echo.

:show_procs
echo === Nginx Status ===
tasklist /fi "imagename eq nginx.exe"
echo.
echo === PHP Status ===
tasklist /fi "imagename eq php-cgi.exe"
echo.
pause

@echo off
cd core\nginx
nginx.exe -s quit > nul 2> nul
cd ..\..
taskkill /F /IM nginx.exe > nul 2> nul
taskkill /F /IM xxfpm.exe > nul 2> nul
taskkill /F /IM php-cgi.exe > nul 2> nul
echo Environment stopped successfully.

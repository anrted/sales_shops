@echo off
echo Stopping local Laravel/Nuxt ports...

for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8088" ^| findstr "LISTENING"') do taskkill /PID %%a /F
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":3000" ^| findstr "LISTENING"') do taskkill /PID %%a /F
taskkill /FI "WINDOWTITLE eq Discount Hub Queue Worker*" /F >nul 2>nul
taskkill /FI "WINDOWTITLE eq Discount Hub Backend*" /F >nul 2>nul
taskkill /FI "WINDOWTITLE eq Discount Hub Frontend*" /F >nul 2>nul

echo Done.
pause

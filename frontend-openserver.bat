@echo off
where npm >nul 2>nul
if errorlevel 1 (
    echo npm was not found in PATH.
    echo Install Node.js LTS, then reopen the terminal and run this file again.
    exit /b 1
)

cd /d "%~dp0frontend"
if not exist ".env" (
    copy ".env.example" ".env"
)
npm install
npm run dev -- --host 0.0.0.0

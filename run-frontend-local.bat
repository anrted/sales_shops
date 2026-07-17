@echo off
set NODE_DIR=C:\Program Files\nodejs
set PATH=%NODE_DIR%;%PATH%

cd /d "%~dp0frontend"
npm run dev -- --host 0.0.0.0

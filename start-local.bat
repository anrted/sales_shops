@echo off
setlocal

set PROJECT_DIR=%~dp0
set BACKEND_DIR=%PROJECT_DIR%backend
set FRONTEND_DIR=%PROJECT_DIR%frontend
set PHP_BIN=C:\OSPanel\modules\PHP-8.4\php.exe
set COMPOSER_PHAR=C:\OSPanel\data\PHP-8.4\default\composer\composer.phar
set NODE_DIR=C:\Program Files\nodejs

if not exist "%PHP_BIN%" (
    echo PHP was not found: %PHP_BIN%
    pause
    exit /b 1
)

if not exist "%COMPOSER_PHAR%" (
    echo Composer was not found: %COMPOSER_PHAR%
    pause
    exit /b 1
)

if not exist "%NODE_DIR%\npm.cmd" (
    echo npm was not found: %NODE_DIR%\npm.cmd
    echo Install Node.js, then run this file again.
    pause
    exit /b 1
)

set PATH=%NODE_DIR%;%PATH%

cd /d "%BACKEND_DIR%"

if not exist ".env" (
    copy ".env.openserver.example" ".env" >nul
)

if not exist "database\database.sqlite" (
    type nul > "database\database.sqlite"
)

if not exist "vendor\autoload.php" (
    echo Installing backend dependencies...
    "%PHP_BIN%" "%COMPOSER_PHAR%" install
    if errorlevel 1 (
        pause
        exit /b 1
    )
)

findstr /C:"APP_KEY=base64:" ".env" >nul 2>nul
if errorlevel 1 (
    echo Generating Laravel app key...
    "%PHP_BIN%" artisan key:generate
    if errorlevel 1 (
        pause
        exit /b 1
    )
)

echo Running migrations...
"%PHP_BIN%" artisan migrate --seed --force
if errorlevel 1 (
    pause
    exit /b 1
)

cd /d "%FRONTEND_DIR%"

if not exist ".env" (
    copy ".env.example" ".env" >nul
)

findstr /C:"http://127.0.0.1:8088/api" ".env" >nul 2>nul
if errorlevel 1 (
    echo NUXT_PUBLIC_API_BASE=http://127.0.0.1:8088/api> ".env"
)

if not exist "node_modules\.package-lock.json" (
    echo Installing frontend dependencies...
    call "%NODE_DIR%\npm.cmd" install
    if errorlevel 1 (
        pause
        exit /b 1
    )
)

echo Starting backend on http://127.0.0.1:8088
start "Discount Hub Backend" "%PROJECT_DIR%run-backend-local.bat"

echo Starting queue worker
start "Discount Hub Queue Worker" "%PROJECT_DIR%run-worker-local.bat"

echo Starting frontend on http://localhost:3000
start "Discount Hub Frontend" "%PROJECT_DIR%run-frontend-local.bat"

echo.
echo Open this URL:
echo http://localhost:3000
echo.
echo API:
echo http://127.0.0.1:8088/api/cities
echo.
echo Queue worker is running in a separate window. Keep it open while parsing.
echo.
pause

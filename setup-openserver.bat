@echo off
set PHP_BIN=C:\OSPanel\modules\PHP-8.4\php.exe
set COMPOSER_PHAR=C:\OSPanel\data\PHP-8.4\default\composer\composer.phar
set PROJECT_DIR=%~dp0
set BACKEND_DIR=%PROJECT_DIR%backend

cd /d "%BACKEND_DIR%"

if not exist ".env" (
    copy ".env.openserver.example" ".env"
)

if not exist "database\database.sqlite" (
    type nul > "database\database.sqlite"
)

"%PHP_BIN%" "%COMPOSER_PHAR%" install
if errorlevel 1 exit /b 1

"%PHP_BIN%" artisan key:generate
if errorlevel 1 exit /b 1

"%PHP_BIN%" artisan migrate --seed
if errorlevel 1 exit /b 1

echo.
echo Backend is ready. Open http://discounts.loc/api/cities

@echo off
set PHP_BIN=C:\OSPanel\modules\PHP-8.4\php.exe

cd /d "%~dp0backend"
"%PHP_BIN%" artisan serve --host=127.0.0.1 --port=8088

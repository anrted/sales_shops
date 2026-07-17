@echo off
set PHP_BIN=C:\OSPanel\modules\PHP-8.4\php.exe

cd /d "%~dp0backend"
"%PHP_BIN%" artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=900

@echo off
set PHP_BIN=C:\OSPanel\modules\PHP-8.4\php.exe
set COMPOSER_PHAR=C:\OSPanel\data\PHP-8.4\default\composer\composer.phar

"%PHP_BIN%" "%COMPOSER_PHAR%" %*

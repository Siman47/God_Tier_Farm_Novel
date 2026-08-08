@echo off
setlocal
title God Tier Farm Dictionary Viewer

cd /d "%~dp0"

if not exist "index.php" (
    echo [ERROR] index.php was not found in:
    echo %CD%
    echo.
    echo Keep this BAT file inside the translation folder.
    pause
    exit /b 1
)

where php >nul 2>nul
if errorlevel 1 (
    echo [ERROR] PHP was not found.
    echo.
    echo Install PHP or add php.exe to the Windows PATH.
    echo If you use XAMPP, add C:\xampp\php to PATH,
    echo or edit PHP_EXE below to use C:\xampp\php\php.exe.
    pause
    exit /b 1
)

set "HOST=127.0.0.1"
set "PORT=8080"
set "URL=http://%HOST%:%PORT%/"

echo Starting God Tier Farm Dictionary Viewer...
echo URL: %URL%
echo.
echo Keep this window open while using the viewer.
echo Press Ctrl+C to stop the server.
echo.

start "" "%URL%"
php -S %HOST%:%PORT% -t "%CD%"

echo.
echo PHP server stopped.
pause

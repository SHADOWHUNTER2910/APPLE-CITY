@echo off
cls
echo ========================================
echo Stock Manager ^& Expiry Tracker
echo ========================================
echo.
echo Starting PHP Development Server...
echo.
echo IMPORTANT:
echo   - Server URL: http://localhost:8000
echo   - Login: admin / Admin@123
echo   - Press Ctrl+C to stop the server
echo.
echo ========================================
echo.

REM Start PHP server
php -S localhost:8000

echo.
echo Server stopped.
pause

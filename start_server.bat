@echo off
cls
echo ========================================
echo Apple City POS
echo ========================================
echo.
echo Starting PHP Development Server...
echo.
echo IMPORTANT:
echo   - Local URL:     http://localhost:8000
echo   - Network URL:   http://YOUR-TAILSCALE-IP:8000
echo   - Login: admin / Admin@123
echo   - Press Ctrl+C to stop the server
echo.
echo ========================================
echo.

REM Start PHP server on all interfaces (accessible via Tailscale)
php -S 0.0.0.0:8000

echo.
echo Server stopped.
pause

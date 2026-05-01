@echo off
echo ========================================
echo  Apple City POS - Build Installer
echo ========================================
echo.

REM Request administrator privileges if not already elevated
>nul 2>&1 "%SYSTEMROOT%\system32\cacls.exe" "%SYSTEMROOT%\system32\config\system"
if '%errorlevel%' NEQ '0' (
    echo Requesting administrative privileges...
    echo Set UAC = CreateObject^("Shell.Application"^) > "%temp%\getadmin.vbs"
    echo UAC.ShellExecute "%~s0", "", "", "runas", 1 >> "%temp%\getadmin.vbs"
    "%temp%\getadmin.vbs"
    exit /B
)
if exist "%temp%\getadmin.vbs" del "%temp%\getadmin.vbs"
pushd "%CD%"
CD /D "%~dp0"

echo Running with administrator privileges...
echo.

REM Install dependencies if needed
if not exist "node_modules\" (
    echo Installing dependencies...
    call npm install
    echo.
)

REM Step 1: Build the core installer (setup-core.exe)
echo [1/3] Building core installer...
call npm run build-installer
if %errorlevel% NEQ 0 (
    echo ERROR: Electron build failed.
    pause
    exit /B 1
)
echo.

REM Step 2: Compile the password-protected launcher wrapper
echo [2/3] Compiling password-protected launcher...
set MAKENSIS="C:\Program Files (x86)\NSIS\makensis.exe"
if not exist %MAKENSIS% set MAKENSIS="C:\Program Files\NSIS\makensis.exe"
if not exist %MAKENSIS% (
    echo ERROR: NSIS not found at expected locations.
    echo        Install from https://nsis.sourceforge.io/Download
    pause
    exit /B 1
)
%MAKENSIS% launcher.nsi
if %errorlevel% NEQ 0 (
    echo ERROR: NSIS compilation failed.
    pause
    exit /B 1
)
echo.

REM Step 3: Clean up intermediate files (keep only the final locked installer)
echo [3/3] Cleaning up...
if exist "dist\win-unpacked\" rmdir /s /q "dist\win-unpacked"
if exist "dist\builder-debug.yml" del "dist\builder-debug.yml"
if exist "dist\builder-effective-config.yaml" del "dist\builder-effective-config.yaml"
if exist "dist\latest.yml" del "dist\latest.yml"
if exist "dist\setup-core.exe.blockmap" del "dist\setup-core.exe.blockmap"
REM Keep setup-core.exe for now (needed if you want to re-run NSIS without rebuilding)

echo.
echo ========================================
echo  Build Complete!
echo ========================================
echo.
echo  Final installer: desktop\dist\AppleCity-POS-Setup.exe
echo  Password:        tHeAnGrYmAn@#$2910
echo.
echo  Give the client: AppleCity-POS-Setup.exe
echo  Keep private:    setup-core.exe (raw installer, no password)
echo.
pause >nul
start "" "%cd%\dist"

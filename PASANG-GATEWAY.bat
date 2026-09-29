@echo off
rem =====================================================================
rem  PASANG WA GATEWAY DI SERVER INI
rem  Dobel klik berkas ini. Kalau Windows menanyakan izin Administrator,
rem  klik "Yes" (diperlukan untuk memasang auto-start).
rem  Aman dijalankan berulang: yang sudah terpasang tidak diulang.
rem =====================================================================
title Pemasangan WA Gateway Lokal
cd /d "%~dp0"

rem --- mode periksa saja tidak perlu Administrator ---
if /i "%~1"=="-CheckOnly" goto :jalan

rem --- naikkan ke Administrator bila belum ---
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Meminta izin Administrator...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -ArgumentList '%*' -Verb RunAs"
    exit /b
)

:jalan

echo ==========================================================
echo   MEMASANG WA GATEWAY DI SERVER INI
echo   Ikuti saja sampai selesai ^(1-3 menit^).
echo ==========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-server.ps1" %*
echo.
echo ==========================================================
echo   SELESAI. Baca "LANGKAH MANUAL BERIKUTNYA" di atas.
echo   Buka http://localhost/wa-gateway lalu pindai QR.
echo ==========================================================
pause

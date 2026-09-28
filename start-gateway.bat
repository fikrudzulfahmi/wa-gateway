@echo off
title WA Gateway Lokal - Engine (Node.js)
cd /d "%~dp0engine"

echo ============================================
echo   WA GATEWAY LOKAL - menjalankan engine...
echo   Jangan tutup jendela ini selama gateway dipakai.
echo   Tutup/CTRL+C = gateway berhenti mengirim pesan.
echo ============================================
echo.

where node >nul 2>nul
if errorlevel 1 (
  echo [X] Node.js tidak ditemukan di PATH.
  echo     Install Node.js LTS dari https://nodejs.org lalu jalankan ulang berkas ini.
  pause
  exit /b 1
)

if not exist "node_modules" (
  echo [i] Dependensi belum ada, menjalankan npm install...
  call npm install
)

node src/index.js
echo.
echo [!] Engine berhenti. Tekan tombol apa saja untuk menutup jendela ini.
pause

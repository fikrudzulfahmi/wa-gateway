@echo off
rem =====================================================================
rem  UPDATE WA GATEWAY
rem  Tarik pembaruan dari GitHub, matikan engine lama, jalankan engine baru.
rem =====================================================================
title Update WA Gateway Lokal
cd /d "%~dp0"

echo ============================================================
echo   UPDATE WA GATEWAY LOKAL
echo ============================================================
echo.

where git >nul 2>nul
if errorlevel 1 (
  echo [X] Git tidak ditemukan di PATH.
  pause
  exit /b 1
)

echo [1/4] Versi sekarang:
git log -1 --format="      %%h %%ad %%s" --date=short
echo.

echo [2/4] Menarik pembaruan (git pull)...
git pull
if errorlevel 1 (
  echo.
  echo [!] git pull gagal. Kalau ada perubahan lokal, simpan dulu / stash.
  echo     Perintah: git stash    lalu jalankan lagi berkas ini.
  pause
  exit /b 1
)

echo.
echo [3/4] Mencari dan mematikan proses di port 3001...
set ADA=0
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":3001" ^| findstr "LISTENING"') do (
  set ADA=1
  echo       Mematikan PID %%P...
  taskkill /PID %%P /F >nul 2>&1
)
if "%ADA%"=="0" echo       Port 3001 sudah bersih.
timeout /t 2 /nobreak >nul

echo.
echo [4/4] Menjalankan engine baru...
echo ============================================================
echo   Jangan tutup jendela ini selama gateway dipakai.
echo ============================================================
cd /d "%~dp0engine"

if not exist "node_modules" (
  echo [i] Dependensi belum ada, menjalankan npm install...
  call npm install
)

node src/index.js
echo.
echo [!] Engine berhenti. Tekan tombol apa saja untuk menutup jendela ini.
pause

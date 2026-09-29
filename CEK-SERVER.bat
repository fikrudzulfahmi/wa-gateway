@echo off
rem =====================================================================
rem  CEK KONDISI GATEWAY (tidak mengubah apa pun)
rem  Dobel klik berkas ini kalau ada yang tidak jalan, atau sebelum
rem  bertanya. Hasilnya bisa dikirim sebagai tangkapan layar.
rem =====================================================================
title Cek Kondisi WA Gateway
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-server.ps1" -CheckOnly
echo.
pause

<#
.SYNOPSIS
    Pemasangan WA Gateway Lokal di server (Windows + XAMPP), aman untuk server remote (AnyDesk).

.DESCRIPTION
    Idempoten - aman dijalankan berulang. Memeriksa prasyarat lebih dulu, lalu:
      1) memastikan skema database wa_gateway ada,
      2) membuat engine\.env dari .env.example bila belum ada,
      3) memasang dependensi engine (npm install --allow-git=all + pin baileys 6.7.24),
      4) menautkan dashboard ke htdocs (junction),
      5) memasang engine sebagai layanan Windows "WAGateway" via NSSM (bila nssm.exe tersedia).

.PARAMETER CheckOnly
    Hanya MEMERIKSA dan menampilkan rencana; tidak mengubah apa pun.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File install-server.ps1 -CheckOnly
    powershell -ExecutionPolicy Bypass -File install-server.ps1
#>
[CmdletBinding()]
param(
    [switch]$CheckOnly,
    [string]$RepoDir  = '',
    [string]$XamppDir = 'C:\xampp',
    [string]$DbUser   = 'root',
    [string]$DbPass   = '',
    [string]$DbName   = 'wa_gateway',
    [string]$ServiceName = 'WAGateway',
    [string]$NodeExe  = '',
    [switch]$SkipService
)

# CATATAN: $MyInvocation.MyCommand.Definition (dan $PSScriptRoot) TIDAK boleh dipakai sebagai
# nilai default di blok param() — hasilnya null dan skrip gagal sebelum berjalan.
# Jadi default dihitung di sini.
if ([string]::IsNullOrWhiteSpace($RepoDir)) {
    $RepoDir = if ($PSScriptRoot) { $PSScriptRoot }
               elseif ($MyInvocation.MyCommand.Path) { Split-Path -Parent $MyInvocation.MyCommand.Path }
               else { (Get-Location).Path }
}
$RepoDir = (Resolve-Path -LiteralPath $RepoDir).Path

$ErrorActionPreference = 'Continue'
$script:gagal = 0
$script:ingin = 0

function Tulis($teks, $warna = 'Gray') { Write-Host $teks -ForegroundColor $warna }
function Ok($teks)   { $script:lulus++; Write-Host ("  [ OK ]   " + $teks) -ForegroundColor Green }
function Warn($teks) { Write-Host ("  [WARN]   " + $teks) -ForegroundColor Yellow }
function Gagal($teks){ $script:gagal++;  Write-Host ("  [GAGAL] " + $teks) -ForegroundColor Red }
function Rencana($teks) { $script:ingin++; Write-Host ("  [AKAN]   " + $teks) -ForegroundColor Cyan }
function Judul($teks) { Write-Host ""; Write-Host $teks -ForegroundColor White -BackgroundColor DarkBlue }
$script:lulus = 0

Judul " PEMASANGAN WA GATEWAY LOKAL "
Tulis ("Repo       : " + $RepoDir)
Tulis ("XAMPP      : " + $XamppDir)
Tulis ("Mode       : " + $(if ($CheckOnly) { 'PERIKSA SAJA (tidak mengubah apa pun)' } else { 'PASANG / PERBARUI' }))
Tulis ("Waktu      : " + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))

$php    = Join-Path $XamppDir 'php\php.exe'
$mysql  = Join-Path $XamppDir 'mysql\bin\mysql.exe'
$dumps  = Join-Path $XamppDir 'mysql\bin\mysqldump.exe'
$engine = Join-Path $RepoDir 'engine'
$dash   = Join-Path $RepoDir 'dashboard'
$schema = Join-Path $RepoDir 'sql\schema.sql'
$linkOk = Join-Path $XamppDir 'htdocs\wa-gateway'

# =====================================================================
Judul " 1. PRASYARAT"
# =====================================================================
$cmdNode = Get-Command node -ErrorAction SilentlyContinue
if ($cmdNode) {
    $vNode = (& node -v) 2>$null
    if ([int]($vNode -replace '^v(\d+).*', '$1') -ge 20) { Ok "Node.js $vNode" } else { Gagal "Node.js $vNode terlalu lama (butuh v20+)" }
    if (-not $NodeExe) { $NodeExe = $cmdNode.Source }
} else { Gagal "Node.js tidak ditemukan di PATH - unduh dari nodejs.org lalu buka PowerShell baru" }

if (Get-Command npm -ErrorAction SilentlyContinue) { Ok ("npm " + ((& npm -v) 2>$null)) } else { Gagal "npm tidak ditemukan (biasanya ikut Node.js)" }
if (Get-Command git -ErrorAction SilentlyContinue) { Ok ("Git " + ((& git --version) 2>$null)) } else { Warn "Git tidak ditemukan - pembaruan nanti pakai unduh ZIP manual" }

if (Test-Path $php)   { Ok ("PHP  " + ((& $php -r 'echo PHP_VERSION;') 2>$null) + "  ($php)") } else { Gagal "PHP XAMPP tidak ada: $php" }
if (Test-Path $mysql) { Ok "mysql.exe XAMPP ditemukan" } else { Gagal "mysql.exe XAMPP tidak ada: $mysql" }
if (Test-Path $dumps) { Ok "mysqldump.exe XAMPP ditemukan (untuk backup)" } else { Warn "mysqldump.exe tidak ada - backup otomatis tidak tersedia" }

# Zona waktu: yang penting OFFSET-nya UTC+7 (Jakarta). Nama zona bisa berbeda-beda
# (mis. mesin berbahasa Inggris: "SE Asia Standard Time") padahal jamnya sama.
$tz    = (tzutil /g) 2>$null
$offset = [System.TimeZoneInfo]::Local.BaseUtcOffset.TotalHours
if ($offset -eq 7) { Ok ("Zona waktu UTC+7 ($tz) - sesuai WIB") }
else { Warn ("Zona waktu UTC$($offset) ($tz) - seharusnya UTC+7. Ubah: tzutil /s `"SE Asia Standard Time`"") }

foreach ($port in @(3306, 80)) {
    $jalan = (Test-NetConnection -ComputerName 127.0.0.1 -Port $port -InformationLevel Quiet -WarningAction SilentlyContinue)
    if ($jalan) { Ok "Port $port melayani (XAMPP jalan)" } else { Warn "Port $port belum melayani - Start Apache/MySQL di XAMPP Control Panel" }
}

# =====================================================================
Judul " 2. BERKAS PROYEK"
# =====================================================================
foreach ($p in @($engine, $dash, $schema, (Join-Path $RepoDir 'start-gateway.bat'))) {
    if (Test-Path $p) { Ok ("ada: " + $p.Replace($RepoDir, '.')) } else { Gagal ("HILANG: " + $p) }
}
if ($script:gagal -gt 0) {
    Tulis ""
    Tulis "Pemasangan dihentikan: perbaiki prasyarat di atas dulu (lihat docs\INSTAL-SERVER.md)." Red
    exit 1
}

# =====================================================================
Judul " 3. DATABASE"
# =====================================================================
# Bedakan "MySQL mati" dari "database kosong" - kalau tidak dibedakan, skrip akan
# mengira server baru perlu impor skema padahal layanannya memang belum dinyalakan.
$koneksiArg = @('-u', $DbUser)
if ($DbPass -ne '') { $koneksiArg += "-p$DbPass" }
$mysqladmin = Join-Path $XamppDir 'mysql\bin\mysqladmin.exe'

$mysqlHidup = $false
if (Test-Path $mysqladmin) {
    $null = & $mysqladmin @koneksiArg ping 2>$null
    $mysqlHidup = ($LASTEXITCODE -eq 0)
} else {
    $mysqlHidup = [bool](Test-NetConnection -ComputerName 127.0.0.1 -Port 3306 -InformationLevel Quiet -WarningAction SilentlyContinue)
}

$jumlahTabel = 0
if (-not $mysqlHidup) {
    Gagal "MySQL belum melayani - buka XAMPP Control Panel lalu klik Start pada MySQL, kemudian jalankan skrip ini lagi"
} else {
    Ok "MySQL melayani"
    $q = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DbName' AND table_name IN ('wa_sessions','wa_outbox','wa_messages','wa_clients','wa_settings','wa_logs','wa_users','wa_inbound');"
    $hasil = (& $mysql @koneksiArg -N -B -e $q) 2>$null
    $jumlahTabel = [int]($hasil | Select-Object -First 1)

    if ($jumlahTabel -ge 8) {
        Ok "Skema database '$DbName' lengkap ($jumlahTabel tabel inti)"
    } elseif ($CheckOnly) {
        Rencana "impor $schema ke database '$DbName' (tabel inti terdeteksi: $jumlahTabel/8)"
    } else {
        Tulis "  ... mengimpor skema database (membuat database bila belum ada)"
        Get-Content $schema -Raw | & $mysql @koneksiArg 2>&1 | Out-Null
        $cek = (& $mysql @koneksiArg -N -B -e $q) 2>$null
        if ([int]($cek | Select-Object -First 1) -ge 8) {
            Ok "Skema database berhasil diimpor"
        } else {
            Gagal "Impor skema gagal - jalankan manual: `"$mysql`" -u $DbUser < `"$schema`""
        }
    }
}

# =====================================================================
Judul " 4. KONFIGURASI ENGINE (.env)"
# =====================================================================
$envFile = Join-Path $engine '.env'
$envCon  = Join-Path $engine '.env.example'
if (Test-Path $envFile) {
    Ok ".env sudah ada (token engine & kredensial DB tersimpan di sini)"
} elseif (Test-Path $envCon) {
    if ($CheckOnly) { Rencana "salin .env.example -> .env" }
    else { Copy-Item $envCon $envFile; Ok "engine\.env dibuat dari .env.example (sesuaikan DB bila bukan XAMPP default)" }
} else { Gagal "engine\.env.example tidak ada - repo tidak lengkap" }

# =====================================================================
Judul " 5. DEPENDENSI ENGINE (npm)"
# =====================================================================
$nm = Join-Path $engine 'node_modules'
if (Test-Path $nm) {
    Push-Location $engine
    $outNpm = ((& npm ls '@whiskeysockets/baileys' --depth=0 2>$null) -join ' ')
    Pop-Location
    if ($outNpm -match 'baileys@([0-9][^\s]*)') { $versi = $Matches[1] } else { $versi = '' }
    if ($versi -like '6.*') { Ok "dependensi terpasang (baileys $versi)" }
    else { Warn "baileys terdeteksi: '$versi' (seharusnya 6.7.24) - jalankan ulang tanpa -CheckOnly" }
} else {
    if ($CheckOnly) { Rencana "npm install --allow-git=all  +  npm install @whiskeysockets/baileys@6.7.24 (di folder engine)" }
    else {
        Tulis "  ... npm install (perlu internet, 1-3 menit)"
        Push-Location $engine
        & npm install --allow-git=all 2>&1 | Out-Null
        & npm install '@whiskeysockets/baileys@6.7.24' 2>&1 | Out-Null
        $ok2 = Test-Path (Join-Path $engine 'node_modules')
        Pop-Location
        if ($ok2) { Ok "dependensi engine terpasang" } else { Gagal "npm install gagal - jalankan manual di folder engine: npm install --allow-git=all" }
    }
}
$logDir = Join-Path $engine 'logs'
if (Test-Path $logDir) { Ok "folder engine\logs ada" }
elseif ($CheckOnly) { Rencana "buat folder engine\logs (untuk layanan Windows)" }
else { New-Item -ItemType Directory -Path $logDir -Force | Out-Null; Ok "folder engine\logs dibuat" }

# =====================================================================
Judul " 6. TAUTAN DASHBOARD KE HTDOCS"
# =====================================================================
if (Test-Path $linkOk) {
    $target = (Get-Item $linkOk).Target
    if ($target -and ($target -join ',') -like "*$dash*") { Ok "junction htdocs\wa-gateway -> dashboard sudah benar" }
    else { Warn "htdocs\wa-gateway sudah ada tapi BUKAN tautan ke $dash (periksa manual, jangan ditimpa)" }
} elseif ($CheckOnly) {
    Rencana "buat junction: $linkOk -> $dash"
} else {
    New-Item -ItemType Junction -Path $linkOk -Target $dash | Out-Null
    if (Test-Path $linkOk) { Ok "junction dibuat: $linkOk -> $dash" } else { Gagal "gagal membuat junction (coba jalankan PowerShell sebagai Administrator)" }
}

# =====================================================================
Judul " 7. AUTO-START (layanan Windows)"
# =====================================================================
# Engine WAJIB hidup tanpa jendela terbuka: kalau dijalankan dengan dobel klik
# start-gateway.bat, dia mati saat sesi remote ditutup / server di-restart.
# Dipakai NSSM bila tersedia; kalau tidak, pakai Task Scheduler bawaan Windows
# (jadi tidak ada unduhan tambahan yang wajib).
$runnerCmd = Join-Path $RepoDir 'run-engine.local.cmd'
$taskAda = $false
$null = schtasks.exe /query /tn $ServiceName 2>$null
if ($LASTEXITCODE -eq 0) { $taskAda = $true }

if ($SkipService) {
    Warn "dilewati (-SkipService). Jalankan manual: start-gateway.bat (tidak tahan logoff/restart!)"
} else {
    $nssm = $null
    foreach ($kandidat in @((Join-Path $RepoDir 'tools\nssm.exe'), (Get-Command nssm -ErrorAction SilentlyContinue).Source)) {
        if ($kandidat -and (Test-Path $kandidat)) { $nssm = $kandidat; break }
    }
    $svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue

    if ($svc) {
        Ok "layanan Windows '$ServiceName' sudah terpasang (status: $($svc.Status))"
        if (-not $CheckOnly -and $svc.Status -ne 'Running') { Start-Service $ServiceName -ErrorAction SilentlyContinue }
    } elseif ($taskAda) {
        Ok "task auto-start '$ServiceName' sudah terpasang (Task Scheduler)"
    } elseif ($CheckOnly) {
        $cara = if ($nssm) { 'NSSM' } else { 'Task Scheduler (tanpa unduhan tambahan)' }
        Rencana "pasang auto-start '$ServiceName' lewat $cara (menjalankan $NodeExe src/index.js di folder engine)"
    } else {
        # skrip peluncur dengan PATH absolut (akun SYSTEM sering tidak punya Node di PATH)
        $isiRunner = "@echo off`r`nrem Dibuat otomatis oleh install-server.ps1. Jangan diubah manual.`r`n" +
                     "cd /d `"$engine`"`r`n" +
                     "`"$NodeExe`" src\index.js >> `"$logDir\out.log`" 2>&1`r`n"
        Set-Content -Path $runnerCmd -Value $isiRunner -Encoding ASCII
        Ok "peluncur dibuat: run-engine.local.cmd (berisi path node.exe absolut)"

        if ($nssm) {
            & $nssm install $ServiceName "$env:ComSpec" "/c `"$runnerCmd`"" | Out-Null
            & $nssm set $ServiceName AppDirectory $engine | Out-Null
            & $nssm set $ServiceName Start SERVICE_AUTO_START | Out-Null
            & $nssm set $ServiceName Description 'WA Gateway lokal (engine Baileys)' | Out-Null
            Start-Service $ServiceName -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 4
            $svc2 = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
            if ($svc2 -and $svc2.Status -eq 'Running') { Ok "layanan '$ServiceName' berjalan (auto-start, via NSSM)" }
            else { Gagal "layanan terpasang tetapi tidak berjalan - lihat $logDir\out.log" }
        } else {
            $arg = @('/create', '/tn', $ServiceName, '/tr', ('"' + $runnerCmd + '"'),
                     '/sc', 'onstart', '/ru', 'SYSTEM', '/rl', 'HIGHEST', '/f')
            $hasilSched = (& schtasks.exe @arg 2>&1) -join ' '
            $null = schtasks.exe /query /tn $ServiceName 2>$null
            if ($LASTEXITCODE -eq 0) {
                Ok "auto-start '$ServiceName' dipasang lewat Task Scheduler (jalan sebagai SYSTEM saat Windows menyala)"
                Tulis "           Mulai sekarang tanpa restart Windows: schtasks /run /tn $ServiceName" DarkGray
            } else {
                Gagal ("gagal memasang task auto-start: " + $hasilSched)
            }
        }
    }
}

# =====================================================================
Judul " 8. VERIFIKASI AKHIR"
# =====================================================================
# READ-ONLY (hanya HTTP GET) -> dijalankan juga pada mode -CheckOnly, karena
# "engine menjawab?" dan "dashboard terbuka?" justru yang paling dibutuhkan saat
# memeriksa masalah di server.
if (-not $CheckOnly) { Start-Sleep -Seconds 3 }
try {
    $h = Invoke-RestMethod -Uri 'http://127.0.0.1:3001/api/health' -TimeoutSec 8
    if ($h.ok) { Ok ("engine menjawab: ok=true, sesi=$($h.sessions_total), tersambung=$($h.sessions_connected)") }
    else { Warn "engine menjawab tetapi ok=false" }
} catch {
    Warn "engine belum menjawab di 127.0.0.1:3001 - jalankan: schtasks /run /tn $ServiceName (atau start-gateway.bat)"
}
try {
    $r = Invoke-WebRequest -Uri 'http://localhost/wa-gateway/index.php?page=login' -UseBasicParsing -TimeoutSec 8
    if ($r.StatusCode -eq 200 -and $r.Content -match 'name="password"') { Ok "dashboard menjawab di http://localhost/wa-gateway" }
    else { Warn "dashboard menjawab tak terduga (HTTP $($r.StatusCode))" }
} catch {
    Warn "dashboard belum bisa dibuka - pastikan Apache jalan dan junction htdocs\wa-gateway sudah dibuat"
}

# =====================================================================
Judul " RINGKASAN "
# =====================================================================
Tulis ("  pemeriksaan lulus : " + $script:lulus)
if ($script:ingin -gt 0) { Tulis ("  tindakan tertunda : " + $script:ingin) Yellow }
if ($script:gagal -gt 0) { Tulis ("  GAGAL             : " + $script:gagal) Red }
if ($script:gagal -gt 0) {
    Tulis ""
    Tulis "  Perbaiki dulu hal bertanda [GAGAL] di atas, lalu jalankan berkas ini lagi (aman diulang)." Red
}

Tulis ""
Tulis "  LANGKAH MANUAL BERIKUTNYA" White
Tulis "   1. XAMPP Control Panel -> Start Apache + MySQL (centang Autostart lewat tombol Config)"
Tulis "   2. Pastikan layanan '$ServiceName' berjalan:  nssm status $ServiceName"
Tulis "   3. Buka http://localhost/wa-gateway -> login admin / admin123 -> panel Scan QR"
Tulis "   4. Pindai QR dari HP (WhatsApp -> Perangkat tertaut -> Tautkan perangkat)"
Tulis "   5. Ganti password dashboard, lalu uji kirim ke nomor sendiri"
Tulis "   6. Hubungkan aplikasi hosting: menu Aplikasi Hosting -> samakan token di sisi hosting"
Tulis ""
Tulis "  Panduan lengkap + troubleshooting: docs\INSTAL-SERVER.md" DarkGray
Tulis ""

if ($script:gagal -gt 0) { exit 1 } else { exit 0 }

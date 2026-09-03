# Menjalankan Sistem SUSENAS (Laravel) di Windows / Laragon.
#   .\run.ps1            -> siapkan (bila perlu) lalu jalankan di http://127.0.0.1:8000
#   .\run.ps1 -Fresh     -> reset database + seed ulang, lalu jalankan
#   .\run.ps1 -Port 9000 -> pakai port lain
param(
    [switch]$Fresh,
    [int]$Port = 8000
)

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

# --- cari PHP ---
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $cand = Get-ChildItem "C:\laragon\bin\php" -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match "php-8\.[23]" } | Sort-Object Name -Descending | Select-Object -First 1
    if ($cand) { $php = Join-Path $cand.FullName "php.exe" }
}
if (-not $php -or -not (Test-Path $php)) {
    Write-Error "PHP tidak ditemukan. Pasang PHP 8.2+ atau jalankan lewat Laragon Terminal."
    exit 1
}
Write-Host "PHP : $php"

# --- composer (hanya jika vendor belum ada) ---
if (-not (Test-Path "vendor")) {
    Write-Host "Memasang dependency (composer install)..."
    $composer = (Get-Command composer -ErrorAction SilentlyContinue).Source
    if ($composer) {
        & $composer install --no-interaction --prefer-dist
    } elseif (Test-Path "C:\laragon\bin\composer\composer.phar") {
        & $php "C:\laragon\bin\composer\composer.phar" install --no-interaction --prefer-dist
    } else {
        Write-Error "Composer tidak ditemukan dan folder vendor kosong."
        exit 1
    }
}

# --- .env + APP_KEY ---
if (-not (Test-Path ".env")) { Copy-Item ".env.example" ".env" }
if (-not (Select-String -Path ".env" -Pattern "^APP_KEY=base64:" -Quiet)) {
    & $php artisan key:generate --force
}

# --- database sqlite ---
if (-not (Test-Path "database\database.sqlite")) {
    New-Item -ItemType File "database\database.sqlite" | Out-Null
    $Fresh = $true
}

if ($Fresh) {
    Write-Host "Reset + seed database..."
    & $php artisan migrate:fresh --seed --force
} else {
    # jalankan migrasi yang belum diterapkan (aman bila sudah mutakhir)
    & $php artisan migrate --force | Out-Null
}

Write-Host ""
Write-Host "  Sistem SUSENAS siap di  http://127.0.0.1:$Port"
Write-Host "  Login contoh: admin/admin123 | rahmawati/pengawas123 | ppl_ani/operator123"
Write-Host "  Ctrl+C untuk berhenti."
Write-Host ""
& $php artisan serve --port $Port

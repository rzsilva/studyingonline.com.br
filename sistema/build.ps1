<#
  Gera o pacote de publicação em .\dist (enviar por FTP para a Locaweb):
    dist\public_html\  -> raiz pública do site (pasta "web" / wwwroot)
    dist\api\          -> código da API, FORA da raiz pública (ou em public_html\_app)
  Uso:  powershell -ExecutionPolicy Bypass -File build.ps1 [-AppInsidePublic]
  Pare o "npm run dev" antes: ele trava arquivos de node_modules.
#>
param([switch]$AppInsidePublic)
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$php = if (Get-Command php -ErrorAction SilentlyContinue) { 'php' } else { 'D:\xampp\php\php.exe' }
$composer = Join-Path $root 'tools\composer.phar'

# npm ci apaga node_modules: aborta se algum vite/esbuild deste projeto estiver rodando (trava esbuild.exe).
$webDir = "$root\web"
$travando = Get-CimInstance Win32_Process -Filter "Name='node.exe' OR Name='esbuild.exe'" |
  Where-Object { $_.CommandLine -like "*$webDir*" -or $_.CommandLine -like '*vite*' }
if ($travando) {
  Write-Host 'Feche antes o "npm run dev" / "vite preview" (processos que travam node_modules):' -ForegroundColor Yellow
  $travando | ForEach-Object { Write-Host "  PID $($_.ProcessId): $($_.CommandLine)" }
  Write-Host 'Ou encerre com: Stop-Process -Id <PID>'
  exit 1
}

try {
  Write-Host '> Web: npm ci + build'
  Push-Location "$root\web"
  npm ci; if ($LASTEXITCODE) { throw 'npm ci falhou' }
  npm run build; if ($LASTEXITCODE) { throw 'build falhou' }
  Pop-Location

  Write-Host '> API: composer install --no-dev'
  Push-Location "$root\api"
  & $php $composer install --no-dev --optimize-autoloader --no-interaction
  if ($LASTEXITCODE) { throw 'composer falhou' }
  Pop-Location

  $dist = "$root\dist"
  if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
  New-Item -ItemType Directory "$dist\public_html" | Out-Null
  Copy-Item "$root\public_html\*" "$dist\public_html" -Recurse

  $appDest = if ($AppInsidePublic) { "$dist\public_html\_app" } else { "$dist\api" }
  New-Item -ItemType Directory $appDest | Out-Null
  foreach ($d in 'bootstrap','config','src','vendor','migrations','bin','storage') {
    Copy-Item "$root\api\$d" "$appDest\$d" -Recurse
  }
  Copy-Item "$root\api\.env.example" $appDest
  Get-ChildItem "$appDest\storage" -Recurse -File | Where-Object { $_.Name -notin '.gitkeep','web.config' } | Remove-Item
  if ($AppInsidePublic) { Copy-Item "$root\api\web.config.deny" "$appDest\web.config" }

  Write-Host "`nPacote pronto em $dist"
  Write-Host 'Lembre: criar o .env no servidor (a partir de .env.example) com APP_ENV=production, APP_DEBUG=false, COOKIE_SECURE=true.'
}
finally {
  # restaura dependências de desenvolvimento localmente, mesmo se algo falhar
  Set-Location $root
  # Composer escreve progresso no stderr; com 'Stop' o PowerShell 5 trataria isso como erro.
  $ErrorActionPreference = 'Continue'
  Push-Location "$root\api"; & $php $composer install --no-interaction --quiet; Pop-Location
}

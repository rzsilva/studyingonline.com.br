@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"

set "PHP_EXE=php"
where php >nul 2>nul
if errorlevel 1 (
    if exist "D:\xampp\php\php.exe" (
        set "PHP_EXE=D:\xampp\php\php.exe"
    ) else (
        echo Nao encontrei o PHP no PATH nem em D:\xampp\php\php.exe.
        pause
        exit /b 1
    )
)

echo ==================================================
echo  Publicar o SISTEMA compilado no FTP de producao
echo ==================================================
echo.

set "COMPILAR="
set /p COMPILAR="Compilar antes (build.ps1 -AppInsidePublic)? (S/N): "
if /i "!COMPILAR!"=="S" (
    powershell -ExecutionPolicy Bypass -File sistema\build.ps1 -AppInsidePublic
    if errorlevel 1 (
        echo.
        echo Falha na compilacao. Nada foi publicado.
        exit /b 1
    )
    echo.
)

rem Codigo 2 = nada a publicar (ver deploy\ftp-sistema.php).
"%PHP_EXE%" deploy\ftp-sistema.php
if errorlevel 2 exit /b 0
if errorlevel 1 goto falha_listagem

echo.
set "CONFIRMA="
set /p CONFIRMA="Publicar esses arquivos agora? (S/N): "
if /i not "!CONFIRMA!"=="S" goto cancelado

echo.
set "APAGAR="
set /p APAGAR="Tambem apagar no servidor os arquivos que nao existem mais no pacote? (S/N): "
if /i "!APAGAR!"=="S" (
    "%PHP_EXE%" deploy\ftp-sistema.php --apply --delete
) else (
    "%PHP_EXE%" deploy\ftp-sistema.php --apply
)
exit /b

:cancelado
echo.
echo Cancelado. Nada foi publicado.
exit /b 0

:falha_listagem
echo.
echo Falha ao listar as alteracoes. Nada foi publicado.
exit /b 1

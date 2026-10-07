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

echo ================================================
echo  Publicar alteracoes no servidor FTP de producao
echo ================================================
echo.

rem MODO vazio = alteracoes pendentes; --commit=N/hash = arquivos de um commit.
set "MODO="

"%PHP_EXE%" deploy\ftp-deploy.php
rem Codigo 2 = nenhuma alteracao pendente (ver deploy\ftp-deploy.php).
if errorlevel 2 goto escolher_commit
if errorlevel 1 goto falha_listagem

echo.
set "CONFIRMA="
set /p CONFIRMA="Publicar esses arquivos agora? (S = sim, N = nao, C = publicar um commit): "
if /i "!CONFIRMA!"=="C" goto escolher_commit
if /i not "!CONFIRMA!"=="S" goto cancelado
goto perguntar_apagar

:escolher_commit
echo.
"%PHP_EXE%" deploy\ftp-deploy.php --listar-commits
if errorlevel 1 goto falha_listagem
echo.
set "ESCOLHA="
set /p ESCOLHA="Numero do commit (1-5), hash de outro commit, ou ENTER para cancelar: "
if not defined ESCOLHA goto cancelado

set "MODO=--commit=!ESCOLHA!"
echo.
"%PHP_EXE%" deploy\ftp-deploy.php "!MODO!"
if errorlevel 2 (
    pause
    exit /b 0
)
if errorlevel 1 goto falha_listagem

echo.
set "CONFIRMA="
set /p CONFIRMA="Publicar esses arquivos agora? (S/N): "
if /i not "!CONFIRMA!"=="S" goto cancelado

:perguntar_apagar
echo.
set "APAGAR="
set /p APAGAR="Tambem apagar no servidor os arquivos removidos? (S/N): "
if /i "!APAGAR!"=="S" (
    "%PHP_EXE%" deploy\ftp-deploy.php !MODO! --apply --delete
) else (
    "%PHP_EXE%" deploy\ftp-deploy.php !MODO! --apply
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

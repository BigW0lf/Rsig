@echo off
echo Arret des anciens processus Apache...
taskkill /F /IM httpd.exe >nul 2>&1
timeout /t 1 /nobreak >nul

echo Demarrage Apache...
start "" "C:\MAMP\bin\apache\bin\httpd.exe" -d "C:\MAMP\bin\apache" -f "C:\MAMP\conf\apache\httpd.conf"
timeout /t 2 /nobreak >nul

netstat -ano | findstr ":80 " | findstr "LISTENING"
if %errorlevel%==0 (
    echo Apache demarre sur le port 80 - OK
) else (
    echo ERREUR : Apache n'ecoute pas sur le port 80
)
pause

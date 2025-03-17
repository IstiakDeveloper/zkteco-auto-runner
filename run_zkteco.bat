@echo off
REM Check if the script is already running minimized
if not "%minimized%"=="1" (
    set minimized=1
    start /min cmd /c %0
    exit /b
)

REM The actual script content starts here
echo ZKTeco Sync started at: %date% %time% >> C:\ZKTeco-Agent\logs\scheduler.log
cd C:\ZKTeco-Agent
C:\laragon\bin\php\php-8.3.12-nts-Win32-vs16-x64\php.exe zkteco_agent.php
echo ZKTeco Sync completed at: %date% %time% >> C:\ZKTeco-Agent\logs\scheduler.log
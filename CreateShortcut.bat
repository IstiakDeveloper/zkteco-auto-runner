@echo off
setlocal enabledelayedexpansion

:: ZKTeco Startup Shortcut Creation Script
echo Setting up ZKTeco Agent in startup...

:: PowerShell script exact path
set "PS_SCRIPT_PATH=C:\ZKTeco-Agent\AutoRunZK.ps1"

:: Startup folder path - not system dependent
set "STARTUP_FOLDER=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"

:: Create shortcut using VBScript
set "VBS_FILE=%TEMP%\CreateZKShortcut.vbs"

echo Set oWS = WScript.CreateObject("WScript.Shell") > "!VBS_FILE!"
echo sLinkFile = "!STARTUP_FOLDER!\ZKTecoAutoRun.lnk" >> "!VBS_FILE!"
echo Set oLink = oWS.CreateShortcut(sLinkFile) >> "!VBS_FILE!"
echo oLink.TargetPath = "powershell.exe" >> "!VBS_FILE!"
echo oLink.Arguments = "-ExecutionPolicy Bypass -WindowStyle Hidden -File ""!PS_SCRIPT_PATH!""" >> "!VBS_FILE!"
echo oLink.Description = "ZKTeco Agent Auto Runner" >> "!VBS_FILE!"
echo oLink.IconLocation = "powershell.exe,0" >> "!VBS_FILE!"
echo oLink.WindowStyle = 7 >> "!VBS_FILE!"
echo oLink.Save >> "!VBS_FILE!"

:: Run VBScript
cscript //nologo "!VBS_FILE!"
del "!VBS_FILE!"

echo Setup completed!
echo After system restart, ZKTeco Agent will automatically run every 5 minutes.
echo.
pause
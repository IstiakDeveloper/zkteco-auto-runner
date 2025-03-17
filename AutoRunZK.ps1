# AutoRunZK.ps1
# Script to run ZKTeco-Agent batch file every 5 minutes

# Batch file path
$BatFilePath = "C:\ZKTeco-Agent\run_zkteco.bat"
$IntervalMinutes = 5

# Log file setup
$logFolder = "C:\ZKTeco-Agent\Logs"
if (-not (Test-Path -Path $logFolder)) {
    New-Item -Path $logFolder -ItemType Directory -Force | Out-Null
}

$logFile = Join-Path -Path $logFolder -ChildPath "AutoRunLog_$(Get-Date -Format 'yyyyMMdd').txt"

# Check if file exists
if (-not (Test-Path -Path $BatFilePath -PathType Leaf)) {
    Write-Host "Error: File '$BatFilePath' not found."
    Add-Content -Path $logFile -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - Error: Batch file not found - $BatFilePath"
    exit 1
}

Write-Host "Running ZKTeco Agent batch file every $IntervalMinutes minutes..."
Add-Content -Path $logFile -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - Autorun service started - $BatFilePath"

# Run forever
while ($true) {
    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    
    try {
        Write-Host "$timestamp - Running batch file: $BatFilePath"
        
        # Run the batch file
        Start-Process -FilePath $BatFilePath -Wait
        
        Add-Content -Path $logFile -Value "$timestamp - Batch file executed successfully"
        Write-Host "$timestamp - Batch file completed successfully."
    }
    catch {
        $errorMessage = $_.Exception.Message
        Write-Host "$timestamp - Error: Problem executing batch file - $errorMessage"
        Add-Content -Path $logFile -Value "$timestamp - Error: Problem executing batch file - $errorMessage"
    }
    
    # Wait for specified time
    Write-Host "Next run in $IntervalMinutes minutes..."
    Start-Sleep -Seconds ($IntervalMinutes * 60)
}
param (
    [Parameter(Mandatory=$true)]
    [string]$Extensions
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$phpDir = Join-Path $scriptDir "core\php"
$extDir = Join-Path $phpDir "ext"
$iniFile = Join-Path $phpDir "php.ini"

$extList = $Extensions -split "," | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne "" }

foreach ($ext in $extList) {
    Write-Host "`n=== Installing/Enabling extension: $ext ==="
    
    $cleanName = $ext -replace "^php_", "" -replace "\.dll$", ""
    $dllName = "php_${cleanName}.dll"
    $dllPath = Join-Path $extDir $dllName

    $needsDownload = $true

    if (Test-Path $dllPath) {
        Write-Host "[INFO] $dllName already exists in ext/ directory."
        $needsDownload = $false
    }

    if ($needsDownload) {
        Write-Host "[INFO] $dllName not found locally. Searching PECL for '$cleanName'..."
        
        $apiUrl = "https://pecl.php.net/rest/r/$cleanName/allreleases.xml"
        try {
            $xml = Invoke-RestMethod -Uri $apiUrl -ErrorAction Stop -TimeoutSec 5
            $releases = @($xml.a.r)
            
            $downloaded = $false
            foreach ($release in $releases | Select-Object -First 5) {
                $version = $release.v
                if ([string]::IsNullOrWhiteSpace($version)) { continue }
                
                $zipUrl = "https://windows.php.net/downloads/pecl/releases/$cleanName/$version/php_${cleanName}-${version}-8.3-ts-vs16-x64.zip"
                Write-Host " -> Trying version $version..."
                
                $tempZip = Join-Path $scriptDir "temp_$cleanName.zip"
                try {
                    Invoke-WebRequest -Uri $zipUrl -OutFile $tempZip -ErrorAction Stop -UseBasicParsing -TimeoutSec 5
                    $downloaded = $true
                    Write-Host "    [OK] Downloaded successfully."
                    break
                } catch {
                    Write-Host "    [x] Not found for Windows PHP 8.3 TS x64."
                    if (Test-Path $tempZip) { Remove-Item $tempZip -Force }
                }
            }

            if (-not $downloaded) {
                Write-Host "[ERROR] Could not find a compiled Windows DLL for '$cleanName' on PECL for PHP 8.3."
                continue
            }

            $tempDir = Join-Path $scriptDir "temp_$cleanName"
            Write-Host " -> Extracting zip..."
            Expand-Archive -Path $tempZip -DestinationPath $tempDir -Force
            
            $extractedDll = Get-ChildItem -Path $tempDir -Filter "*$cleanName*.dll" -Recurse | Select-Object -First 1
            if ($extractedDll) {
                Copy-Item $extractedDll.FullName -Destination $extDir -Force
                $dllName = $extractedDll.Name
                Write-Host " -> Successfully installed $dllName to ext/."
            } else {
                Write-Host "[ERROR] DLL not found in the downloaded zip."
            }

            Remove-Item $tempZip -Force
            Remove-Item $tempDir -Recurse -Force
            
            if (-not $extractedDll) {
                continue
            }

        } catch {
            Write-Host "[ERROR] Extension '$cleanName' not found on PECL or network error."
            continue
        }
    }

    $iniContent = Get-Content $iniFile -Raw
    
    if ($iniContent -match "(?m)^extension\s*=\s*$dllName$") {
        Write-Host "[OK] $dllName is already enabled in php.ini."
    } elseif ($iniContent -match "(?m)^;extension\s*=\s*$dllName$") {
        $iniContent = $iniContent -replace "(?m)^;extension\s*=\s*$dllName$", "extension=$dllName"
        Set-Content -Path $iniFile -Value $iniContent
        Write-Host "[OK] Uncommented $dllName in php.ini."
    } else {
        Add-Content -Path $iniFile -Value "`nextension=$dllName"
        Write-Host "[OK] Added extension=$dllName to php.ini."
    }
}

Write-Host "`nAll done! Please restart the environment (stop.bat -> start.bat) to apply changes."

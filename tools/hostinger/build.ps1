$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$outputRoot = Join-Path $projectRoot 'deployment/hostinger'
if (Test-Path -LiteralPath $outputRoot) {
    throw 'deployment/hostinger already exists. Rename that folder before rebuilding to preserve your previous package.'
}
if (!(Test-Path -LiteralPath (Join-Path $projectRoot 'vendor/autoload.php'))) {
    throw 'Install Composer dependencies first: composer install --no-dev --prefer-dist --optimize-autoloader'
}
$webRoot = Join-Path $outputRoot 'public_html'
$excludedRoot = Join-Path $outputRoot 'not-for-upload'
$databaseRoot = Join-Path $outputRoot 'database'
$privateRoot = Join-Path $outputRoot 'private-transfer'
foreach ($directory in @($webRoot, $excludedRoot, $databaseRoot, $privateRoot)) {
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
}
# Copy from an explicit application allowlist. Never package .git, sessions or local data.
$runtimeDirectories = @('analytics','assets','attendance','calendar','includes',
    'reports','sections','settings','sms','students','users','vendor','migrations')
foreach ($name in $runtimeDirectories) {
    Copy-Item -LiteralPath (Join-Path $projectRoot $name) -Destination $webRoot -Recurse
}
foreach ($name in @('.htaccess','index.php','dashboard.php','heartbeat.php','logout.php',
    'profile.php','qr_image.php','register.php','security_audit.php','student_photo.php')) {
    Copy-Item -LiteralPath (Join-Path $projectRoot $name) -Destination $webRoot
}
New-Item -ItemType Directory -Path (Join-Path $webRoot 'config') | Out-Null
foreach ($name in @('database.php','deployment_security.php','hosting_environment.php','schedule_rules.php')) {
    Copy-Item -LiteralPath (Join-Path $projectRoot "config/$name") -Destination (Join-Path $webRoot 'config')
}
foreach ($name in @('storage/private','storage/sessions','uploads/students','qrcodes')) {
    New-Item -ItemType Directory -Path (Join-Path $webRoot $name) -Force | Out-Null
}
# These copies are for local reference only; source files remain in place.
foreach ($name in @('tests','docs','.github','.qa','tools','README.md','SECURITY_REVIEW.md',
    'nixpacks.toml','package.json','package-lock.json','composer.json','composer.lock','.gitignore')) {
    $source = Join-Path $projectRoot $name
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $excludedRoot -Recurse }
}
foreach ($name in @('database.example.php','SETTINGS_SECURITY.md')) {
    $source = Join-Path $projectRoot "config/$name"
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $excludedRoot }
}
Copy-Item -LiteralPath (Join-Path $projectRoot 'attendance_system.sql') -Destination $databaseRoot
Copy-Item -LiteralPath (Join-Path $projectRoot 'config/database_schema_update.sql') -Destination $databaseRoot
foreach ($name in @('pii_key.php','settings_key.php')) {
    $source = Join-Path $projectRoot "config/$name"
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $privateRoot }
}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'hosting.example.php') -Destination $privateRoot
Copy-Item -LiteralPath (Join-Path $projectRoot 'docs/HOSTINGER_DEPLOYMENT.md') -Destination (Join-Path $outputRoot 'START-HERE.md')
# ZipFile includes dotfiles such as .htaccess, unlike Compress-Archive.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipPath = Join-Path $outputRoot 'hostinger-upload.zip'
[IO.Compression.ZipFile]::CreateFromDirectory($webRoot, $zipPath)
$archive = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $names = @($archive.Entries | ForEach-Object { $_.FullName.Replace('\','/') })
    foreach ($required in @('.htaccess','index.php','vendor/autoload.php','config/hosting_environment.php','attendance/finalize_absences.php')) {
        if ($required -notin $names) { throw "Required archive entry missing: $required" }
    }
    foreach ($entry in $names) {
        if ($entry -match '^(tests|docs|tools|deployment|\.git|\.qa)/' -or
            $entry -match '^config/(pii_key|settings_key)\.php$' -or
            $entry -match '^(storage|uploads|qrcodes)/.+[^/]$') {
            throw "Private/development data found in upload archive: $entry"
        }
    }
    Write-Output "Verified $($names.Count) ZIP entries."
} finally { $archive.Dispose() }
Write-Output "Upload ZIP: $zipPath"
Write-Output 'Read deployment/hostinger/START-HERE.md before uploading. Private keys and database import files are separate.'

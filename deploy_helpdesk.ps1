$src = $PSScriptRoot
$dst = 'C:\xampp\php\php.exe\htdocs\helpdesk'
$items = @(
    "admin.php",
    "approve_file.php",
    "auth.css",
    "db.php",
    "delete_file.php",
    "delete_user.php",
    "download.php",
    "FEATURES_CHANGELOG.md",
    "import-schema.php",
    "index.php",
    "inspect-db.php",
    "listing.txt",
    "logo.svg",
    "logout.php",
    "mark_notifications_read.php",
    "README.md",
    "script.js",
    "search_index.txt",
    "setup.php",
    "SOURCE_FILES.md",
    "style.css",
    "test-db.php",
    "topics.json",
    "topics.php",
    "update-schema.sql",
    "upload.php",
    "user.php",
    "view_file.php"
)
if (-not (Test-Path -Path $dst)) {
    New-Item -ItemType Directory -Path $dst | Out-Null
}

$dstSupport = Join-Path -Path $dst -ChildPath 'Support-HelpDesk'
if (-not (Test-Path -Path $dstSupport)) {
    New-Item -ItemType Directory -Path $dstSupport | Out-Null
}

$dstUploads = Join-Path -Path $dst -ChildPath 'uploads'
if (-not (Test-Path -Path $dstUploads)) {
    New-Item -ItemType Directory -Path $dstUploads | Out-Null
}

$dstSupportUploads = Join-Path -Path $dstSupport -ChildPath 'uploads'
if (-not (Test-Path -Path $dstSupportUploads)) {
    New-Item -ItemType Directory -Path $dstSupportUploads | Out-Null
}
foreach ($item in $items) {
    $sourcePath = Join-Path -Path $src -ChildPath $item
    if (Test-Path -Path $sourcePath) {
        $destinationPath = Join-Path -Path $dst -ChildPath $item
        if ((Get-Item -Path $sourcePath).PSIsContainer) {
            Copy-Item -Path $sourcePath -Destination $destinationPath -Recurse -Force
        } else {
            Copy-Item -Path $sourcePath -Destination $destinationPath -Force
        }
    } else {
        Write-Host "Missing source item: $sourcePath"
    }
}
Write-Host "Deployment completed to $dst"
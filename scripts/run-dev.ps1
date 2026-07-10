if (-not $env:MONGODB_URI) {
    Write-Error "Defina MONGODB_URI antes de iniciar o portal."
    exit 1
}

if (-not $env:MONGODB_DATABASE) {
    $env:MONGODB_DATABASE = "activity"
}

$env:MONGODB_ACTIVITY_COLLECTION = if ($env:MONGODB_ACTIVITY_COLLECTION) { $env:MONGODB_ACTIVITY_COLLECTION } else { "fmu_activity_control" }
$env:MONGODB_USER_COLLECTION = if ($env:MONGODB_USER_COLLECTION) { $env:MONGODB_USER_COLLECTION } else { "fmu_user_control" }

php -d extension=.\vendor\php-ext\mongodb\php_mongodb.dll -S 127.0.0.1:8000 -t public

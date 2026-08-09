@php
$tools = 'C:\CURSOR\tools'
$env:Path = "$tools\php;$tools\node;$tools\git\cmd;$tools\git\mingw64\bin;" + $env:Path
Set-Alias php "$tools\php\php.exe" -Scope Script
function composer { & "$tools\php\php.exe" "$tools\composer.phar" @args }
Write-Host "AR Sistemas toolchain loaded (php, composer, node, git)."

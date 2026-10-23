param([string]$PhpDirectory = 'C:\xampp\php', [switch]$AsSystem)
$ErrorActionPreference = 'Stop'
$bcvProject = Split-Path -Parent $PSScriptRoot
$bcvWorker = Join-Path $bcvProject 'php\update-bcv.php'
$bcvPhp = Join-Path $PhpDirectory 'php-win.exe'
if (!(Test-Path -LiteralPath $bcvWorker) -or !(Test-Path -LiteralPath $bcvPhp)) { throw 'No se encontró PHP o el actualizador BCV.' }

# Preserve HTTPS verification using this Windows installation's trusted roots.
$bcvRoots = Get-ChildItem Cert:\LocalMachine\Root | Sort-Object Thumbprint -Unique
$bcvBundle = foreach ($cert in $bcvRoots) {
    '-----BEGIN CERTIFICATE-----'
    [Convert]::ToBase64String($cert.RawData, [Base64FormattingOptions]::InsertLineBreaks)
    '-----END CERTIFICATE-----'
}
[IO.File]::WriteAllLines((Join-Path $bcvProject 'php\runtime\bcv-ca.pem'), $bcvBundle, [Text.Encoding]::ASCII)
$bcvAction = New-ScheduledTaskAction -Execute $bcvPhp -Argument ('"' + $bcvWorker + '"') -WorkingDirectory $bcvProject
$bcvTrigger = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes 15)
$bcvSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 3) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$bcvPrincipal = if ($AsSystem) { New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Limited } else { New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited }
$bcvTaskName = 'Nexo-BCV-' + (Split-Path -Leaf $bcvProject)
Register-ScheduledTask -TaskName $bcvTaskName -Action $bcvAction -Trigger $bcvTrigger -Settings $bcvSettings -Principal $bcvPrincipal -Description 'Consulta oficial del BCV y tasas por empresa de Nexo. Reintenta cada 15 minutos y respeta Fecha Valor.' -Force | Select-Object TaskName, State

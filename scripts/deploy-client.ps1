param(
    [Parameter(Mandatory = $true)]
    [string] $Client,

    [string] $HookUrl = $null
)

$envName = "FORGE_DEPLOY_HOOK_" + ($Client.ToUpperInvariant() -replace '[^A-Z0-9]', '_')
if (-not $HookUrl) {
    $HookUrl = [Environment]::GetEnvironmentVariable($envName)
}
if (-not $HookUrl) {
    $HookUrl = [Environment]::GetEnvironmentVariable("FORGE_DEPLOY_HOOK")
}
if (-not $HookUrl) {
    Write-Error "Missing deploy hook. Set $envName or FORGE_DEPLOY_HOOK, or pass -HookUrl."
    exit 1
}

Write-Host "Triggering Forge deployment for $Client..."
Invoke-RestMethod -Method Post -Uri $HookUrl | Out-Null
Write-Host "Deploy hook accepted. Check Laravel Forge for deployment progress."

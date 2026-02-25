#!/usr/bin/env pwsh

# Auto-commit script for Atlas Demo
# Run this in a terminal to watch and commit changes automatically

$repoPath = Split-Path -Parent $PSCommandPath
Set-Location $repoPath

Write-Host "🔍 Starting auto-commit watcher..." -ForegroundColor Green

while ($true) {
    Start-Sleep -Seconds 5
    
    $status = git status --porcelain
    
    if ($status) {
        git add -A
        $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        git commit -m "Auto-commit: Changes at $timestamp"
        Write-Host "✅ Committed at $timestamp" -ForegroundColor Cyan
    }
}

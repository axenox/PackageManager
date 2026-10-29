param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$GitHubRepository,
    [string]$RepoPath = "cleanup.git",
    [int]$PullRequestNumber = 0
)

$ErrorActionPreference = "Stop"
$temporaryDirectory = (Get-Location).Path
$ReplacementFile = Join-Path $PSScriptRoot "replacements.txt"

if (-not (Test-Path -LiteralPath $ReplacementFile -PathType Leaf)) {
    throw "Required replacements.txt not found in $PSScriptRoot."
}

if (-not [System.IO.Path]::IsPathRooted($RepoPath)) {
    $RepoPath = Join-Path $temporaryDirectory $RepoPath
}

Set-Location -LiteralPath $RepoPath

function Assert-LastCommand {
    param([string]$Action)

    if ($LASTEXITCODE -ne 0) {
        throw "$Action failed with exit code $LASTEXITCODE."
    }
}

function Get-RecoveredBranchName {
    param(
        [int]$Number,
        [string]$Title
    )

    $slug = $Title -replace '^(?i:fix|new|feature|docs|ref|refactor)[\s:/_-]+', ''
    $slug = ($slug.ToLowerInvariant() -replace '[^a-z0-9._-]+', '-') -replace '-+', '-'
    $slug = $slug.Trim([char[]]'.-_')

    if ([string]::IsNullOrWhiteSpace($slug)) {
        $slug = "recovered"
    }
    if ($slug.Length -gt 80) {
        $slug = $slug.Substring(0, 80).TrimEnd([char[]]'.-_')
    }

    return "pr/$Number-$slug"
}

<#
.SYNOPSIS
Reads literal search values using git-filter-repo's replacement-file syntax.
#>
function Get-ReplacementSearchValues {
    param([string]$Path)

    $lineNumber = 0
    foreach ($line in Get-Content -LiteralPath $Path -Encoding UTF8) {
        $lineNumber++
        if ($line.Length -eq 0) {
            continue
        }

        # git-filter-repo splits at the last delimiter and defaults to literal matching.
        $delimiterIndex = $line.LastIndexOf("==>", [System.StringComparison]::Ordinal)
        $searchValue = $line
        if ($delimiterIndex -ge 0) {
            $searchValue = $line.Substring(0, $delimiterIndex)
        }

        if ($searchValue.StartsWith("regex:", [System.StringComparison]::Ordinal) -or
            $searchValue.StartsWith("glob:", [System.StringComparison]::Ordinal)) {
            throw "Replacement file line ${lineNumber}: regex and glob rules are not supported by literal history verification."
        }
        if ($searchValue.StartsWith("literal:", [System.StringComparison]::Ordinal)) {
            $searchValue = $searchValue.Substring(8)
        }
        if ($searchValue.Length -eq 0) {
            throw "Replacement file line ${lineNumber}: the search value must not be empty."
        }

        $searchValue
    }
}

<#
.SYNOPSIS
Returns history entries matching any replacement search value, failing on Git errors.
#>
function Get-ReplacementHistoryMatches {
    param(
        [string]$Revision,
        [string[]]$SearchValues
    )

    foreach ($searchValue in $SearchValues) {
        git --git-dir=$RepoPath log $Revision "-S$searchValue" --oneline
        Assert-LastCommand "Checking rewritten history"
    }
}

$replacementSearchValues = @(Get-ReplacementSearchValues -Path $ReplacementFile)
if ($replacementSearchValues.Count -eq 0) {
    throw "Replacement file contains no search values: $ReplacementFile"
}

$repositoryGitDirectory = $RepoPath
if (Test-Path -LiteralPath (Join-Path $RepoPath ".git")) {
    $repositoryGitDirectory = Join-Path $RepoPath ".git"
}
$isBareRepository = git --git-dir=$repositoryGitDirectory rev-parse --is-bare-repository
Assert-LastCommand "Checking repository"

# Preserve origin because git-filter-repo may remove it.
$originUrl = git --git-dir=$repositoryGitDirectory remote get-url origin
Assert-LastCommand "Reading origin URL"

if ($isBareRepository -ne "true") {
    $sourceRoot = git rev-parse --show-toplevel
    Assert-LastCommand "Finding source repository root"
    $sourceRoot = (Get-Item -LiteralPath $sourceRoot).FullName
    $mirrorName = "$(Split-Path -Leaf $sourceRoot)-recovery.git"
    $mirrorPath = Join-Path (Split-Path -Parent $sourceRoot) $mirrorName

    if (Test-Path -LiteralPath $mirrorPath) {
        throw "Recovery mirror path already exists: $mirrorPath. To resume an earlier recovery, rerun with -RepoPath `"$mirrorPath`". Otherwise choose a separate bare mirror; the existing path was not changed."
    }

    Write-Host "Creating recovery mirror at $mirrorPath. The source clone will not be changed."
    git clone --mirror --no-hardlinks -- $sourceRoot $mirrorPath
    Assert-LastCommand "Creating recovery mirror"

    # A local mirror initially points to the source clone and enables mirror pushes.
    git --git-dir=$mirrorPath remote set-url origin $originUrl
    Assert-LastCommand "Setting recovery mirror origin"
    git --git-dir=$mirrorPath config remote.origin.mirror false
    Assert-LastCommand "Disabling mirror pushes in recovery mirror"

    foreach ($setting in @("user.name", "user.email")) {
        $value = git config --get $setting
        $configExitCode = $LASTEXITCODE
        if ($configExitCode -eq 0) {
            git --git-dir=$mirrorPath config $setting $value
            Assert-LastCommand "Copying Git $setting to recovery mirror"
        }
        elseif ($configExitCode -ne 1) {
            Assert-LastCommand "Reading Git $setting"
        }
    }

    $RepoPath = $mirrorPath
    Set-Location -LiteralPath $RepoPath
}

$pullRequests = @()
if ($PullRequestNumber -ne 0) {
    $uri = "https://api.github.com/repos/$GitHubRepository/pulls/$PullRequestNumber"
    $pullRequests = @(Invoke-RestMethod -Uri $uri -Headers @{
        Accept = "application/vnd.github+json"
        "User-Agent" = "ExFace-PR-Recovery"
    })
}
else {
    $page = 1

    do {
        $uri = "https://api.github.com/repos/$GitHubRepository/pulls?state=open&per_page=100&page=$page"
        $response = Invoke-RestMethod -Uri $uri -Headers @{
            Accept = "application/vnd.github+json"
            "User-Agent" = "ExFace-PR-Recovery"
        }

        $pageCount = 0

        # Explicit foreach unwraps Invoke-RestMethod arrays in Windows PowerShell 5.1.
        foreach ($pullRequest in $response) {
            $pullRequests += $pullRequest
            $pageCount++
        }

        $page++
    } while ($pageCount -eq 100)

    if ($pullRequests.Count -eq 0) {
        Write-Host "No open pull requests found."
        exit 0
    }
}

$metadata = @()

foreach ($pullRequest in $pullRequests) {
    $metadata += [PSCustomObject]@{
        Number = [int]$pullRequest.number
        Title  = [string]$pullRequest.title
        Base   = [string]$pullRequest.base.ref
        Head   = [string]$pullRequest.head.ref
        Author = [string]$pullRequest.user.login
    }
}

if ($PullRequestNumber -eq 0) {
    Write-Host "`nOpen pull requests:"
}
else {
    Write-Host "`nSelected pull request:"
}
$metadata | Format-Table Number, Base, Author, Head, Title -AutoSize

if ($PullRequestNumber -eq 0) {
    $selectedNumber = 0
    $answer = Read-Host "Enter one PR number to process"

    if (-not [int]::TryParse($answer, [ref]$selectedNumber)) {
        throw "Invalid PR number: $answer"
    }

    $PullRequestNumber = $selectedNumber
}

$metadata = @($metadata | Where-Object { $_.Number -eq $PullRequestNumber })
if ($metadata.Count -ne 1) {
    throw "PR #$PullRequestNumber was not found."
}

$pr = $metadata[0]
$archiveBranch = Get-RecoveredBranchName -Number $pr.Number -Title $pr.Title
$archiveRef = "refs/heads/$archiveBranch"
$legacyArchiveBranch = "archive/pr-$($pr.Number)"
$legacyArchiveRef = "refs/heads/$legacyArchiveBranch"

git --git-dir=$RepoPath ls-remote --exit-code --heads origin $archiveRef | Out-Null
$remoteArchiveExitCode = $LASTEXITCODE

if ($remoteArchiveExitCode -eq 0) {
    Write-Host "Archive branch $archiveBranch already exists on origin. Nothing to do."
    exit 0
}
if ($remoteArchiveExitCode -ne 2) {
    Assert-LastCommand "Checking remote archive branch for PR #$($pr.Number)"
}

git --git-dir=$RepoPath ls-remote --exit-code --heads origin $legacyArchiveRef | Out-Null
$legacyRemoteExitCode = $LASTEXITCODE
if ($legacyRemoteExitCode -eq 0) {
    throw "Legacy branch $legacyArchiveBranch already exists on origin. Rename or delete it before continuing."
}
if ($legacyRemoteExitCode -ne 2) {
    Assert-LastCommand "Checking legacy remote branch for PR #$($pr.Number)"
}

git --git-dir=$RepoPath show-ref --verify --quiet $archiveRef
$localArchiveExitCode = $LASTEXITCODE
$rebuildConfirmed = $false

if ($localArchiveExitCode -eq 1) {
    git --git-dir=$RepoPath show-ref --verify --quiet $legacyArchiveRef
    $legacyLocalExitCode = $LASTEXITCODE

    if ($legacyLocalExitCode -eq 0) {
        $archiveBranch = $legacyArchiveBranch
        $archiveRef = $legacyArchiveRef
        $localArchiveExitCode = 0
    }
    elseif ($legacyLocalExitCode -ne 1) {
        Assert-LastCommand "Checking legacy local branch for PR #$($pr.Number)"
    }
}

if ($localArchiveExitCode -eq 0) {
    $archiveSubject = git --git-dir=$RepoPath show -s --format="%s" $archiveRef
    Assert-LastCommand "Reading local archive branch for PR #$($pr.Number)"

    if ($archiveSubject -like "Recovered PR #$($pr.Number):*") {
        $matches = @(Get-ReplacementHistoryMatches -Revision $archiveRef -SearchValues $replacementSearchValues)

        if ($matches.Count -gt 0) {
            throw "An old value from the replacement file still exists in $archiveBranch. It was not pushed."
        }

        if ($archiveBranch -eq $legacyArchiveBranch) {
            $newArchiveBranch = Get-RecoveredBranchName -Number $pr.Number -Title $pr.Title
            git --git-dir=$RepoPath branch -m $legacyArchiveBranch $newArchiveBranch
            Assert-LastCommand "Renaming local archive branch for PR #$($pr.Number)"
            $archiveBranch = $newArchiveBranch
            $archiveRef = "refs/heads/$archiveBranch"
        }

        Write-Host "`nCompleted local archive found:"
        git --git-dir=$RepoPath show --stat --oneline $archiveRef
        Assert-LastCommand "Showing local archive branch for PR #$($pr.Number)"

        $confirmation = Read-Host "Type PUSH to publish $archiveBranch or REBUILD to replace it"
        if ($confirmation -ceq "PUSH") {
            git --git-dir=$RepoPath push origin "${archiveRef}:${archiveRef}"
            Assert-LastCommand "Publishing PR #$($pr.Number)"
            Write-Host "Published $archiveBranch."
            exit 0
        }
        if ($confirmation -ceq "REBUILD") {
            $rebuildConfirmed = $true
        }
        else {
            Write-Host "Archive branch remains local only."
            exit 0
        }
    }

    if (-not $rebuildConfirmed) {
        Write-Host "Local branch $archiveBranch is an incomplete recovery attempt."
        $confirmation = Read-Host "Type REBUILD to discard that incomplete attempt and retry"
        if ($confirmation -cne "REBUILD") {
            throw "Cancelled."
        }
    }

    $worktreePath = $null
    foreach ($line in @(git --git-dir=$RepoPath worktree list --porcelain)) {
        if ($line -like "worktree *") {
            $worktreePath = $line.Substring(9)
            continue
        }

        if ($line -eq "branch $archiveRef" -and $null -ne $worktreePath) {
            git --git-dir=$RepoPath worktree remove --force $worktreePath
            Assert-LastCommand "Removing incomplete worktree for PR #$($pr.Number)"
            break
        }
    }

    git --git-dir=$RepoPath branch -D $archiveBranch
    Assert-LastCommand "Removing incomplete archive branch for PR #$($pr.Number)"

    $archiveBranch = Get-RecoveredBranchName -Number $pr.Number -Title $pr.Title
    $archiveRef = "refs/heads/$archiveBranch"
}
elseif ($localArchiveExitCode -ne 1) {
    Assert-LastCommand "Checking local archive branch for PR #$($pr.Number)"
}

$recoveredRef = "refs/heads/recovered/pr-$($pr.Number)"
$reuseRecovered = $false
git --git-dir=$RepoPath show-ref --verify --quiet $recoveredRef
$recoveredExitCode = $LASTEXITCODE

if ($recoveredExitCode -eq 0) {
    $recoveredMatches = @(Get-ReplacementHistoryMatches -Revision $recoveredRef -SearchValues $replacementSearchValues)

    if ($recoveredMatches.Count -eq 0) {
        $confirmation = Read-Host "A filtered recovered PR already exists. Type REUSE to continue with it"
        $reuseRecovered = $confirmation -ceq "REUSE"
    }
}
elseif ($recoveredExitCode -ne 1) {
    Assert-LastCommand "Checking recovered branch for PR #$($pr.Number)"
}

if (-not $reuseRecovered) {
    $confirmation = Read-Host "Type IMPORT to fetch PR #$($pr.Number)"
    if ($confirmation -cne "IMPORT") {
        throw "Cancelled."
    }

    foreach ($pr in $metadata) {
        $source = "refs/pull/$($pr.Number)/head"
        $target = "refs/heads/recovered/pr-$($pr.Number)"

        Write-Host "Importing PR #$($pr.Number)..."
        git --git-dir=$RepoPath fetch --no-tags origin "+${source}:${target}"
        Assert-LastCommand "Importing PR #$($pr.Number)"
    }

    $confirmation = Read-Host "Type FILTER to rewrite the imported PR history"
    if ($confirmation -cne "FILTER") {
        throw "Cancelled. Imported branches have not been filtered."
    }

    git --git-dir=$RepoPath filter-repo --force --replace-text $ReplacementFile
    Assert-LastCommand "Filtering PR histories"

    # Restore origin when filter-repo removed it.
    $remotes = @(git --git-dir=$RepoPath remote)
    if ($remotes -notcontains "origin") {
        git --git-dir=$RepoPath remote add origin $originUrl
        Assert-LastCommand "Restoring origin"
    }
}

Write-Host "`nRebuilding comparable archive branches:"

$temporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) "exface-pr-recovery-$([guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory -Path $temporaryRoot | Out-Null

foreach ($pr in $metadata) {
    $baseRef = "refs/heads/$($pr.Base)"
    $recoveredRef = "refs/heads/recovered/pr-$($pr.Number)"
    $archiveBranch = Get-RecoveredBranchName -Number $pr.Number -Title $pr.Title
    $archiveRef = "refs/heads/$archiveBranch"
    $patchPath = Join-Path $temporaryRoot "pr-$($pr.Number).patch"
    $worktreePath = Join-Path $temporaryRoot "pr-$($pr.Number)"

    git --git-dir=$RepoPath show-ref --verify --quiet $archiveRef
    if ($LASTEXITCODE -eq 0) {
        throw "Archive branch $archiveBranch already exists. Delete or rename it before retrying."
    }
    if ($LASTEXITCODE -ne 1) {
        Assert-LastCommand "Checking archive branch for PR #$($pr.Number)"
    }

    $targetCommitsByTree = @{}
    $targetHistory = @(git --git-dir=$RepoPath log $baseRef --format="%T %H")
    Assert-LastCommand "Reading target history for PR #$($pr.Number)"

    foreach ($line in $targetHistory) {
        $parts = $line -split " ", 2
        if (-not $targetCommitsByTree.ContainsKey($parts[0])) {
            $targetCommitsByTree[$parts[0]] = $parts[1]
        }
    }

    $recoveredBoundary = $null
    $matchingTargetCommit = $null
    $recoveredHistory = @(git --git-dir=$RepoPath log --first-parent $recoveredRef --format="%H %T")
    Assert-LastCommand "Reading recovered history for PR #$($pr.Number)"

    foreach ($line in $recoveredHistory) {
        $parts = $line -split " ", 2
        $commit = $parts[0]
        $tree = $parts[1]

        if ($targetCommitsByTree.ContainsKey($tree)) {
            $recoveredBoundary = $commit
            $matchingTargetCommit = $targetCommitsByTree[$tree]
            break
        }
    }

    if ($null -eq $recoveredBoundary) {
        throw "Could not find a tree-equivalent target commit for PR #$($pr.Number)."
    }

    $commitsToRecover = @(git --git-dir=$RepoPath rev-list --reverse --first-parent "$recoveredBoundary..$recoveredRef")
    Assert-LastCommand "Finding recovered commits for PR #$($pr.Number)"

    if ($commitsToRecover.Count -eq 0) {
        Write-Host "PR #$($pr.Number) has no commits after the recovered boundary and was skipped."
        continue
    }

    git --git-dir=$RepoPath worktree add -b $archiveBranch $worktreePath $matchingTargetCommit
    Assert-LastCommand "Creating archive branch for PR #$($pr.Number)"

    git --git-dir=$RepoPath diff --binary --full-index $recoveredBoundary $recoveredRef "--output=$patchPath"
    Assert-LastCommand "Creating recovered patch for PR #$($pr.Number)"

    git -C $worktreePath apply --index $patchPath
    if ($LASTEXITCODE -ne 0) {
        throw "Applying recovered changes from PR #$($pr.Number) failed. Inspect the worktree at $worktreePath."
    }

    $stagedTree = git -C $worktreePath write-tree
    Assert-LastCommand "Reading staged tree for PR #$($pr.Number)"
    $recoveredTree = git --git-dir=$RepoPath rev-parse "${recoveredRef}^{tree}"
    Assert-LastCommand "Reading recovered tree for PR #$($pr.Number)"
    if ($stagedTree -ne $recoveredTree) {
        throw "Recovered changes for PR #$($pr.Number) do not match its filtered head. Nothing was pushed."
    }

    git -C $worktreePath diff --cached --quiet
    $stagedDiffExitCode = $LASTEXITCODE
    if ($stagedDiffExitCode -eq 0) {
        throw "The selected commits for PR #$($pr.Number) produced no changes."
    }
    if ($stagedDiffExitCode -ne 1) {
        Assert-LastCommand "Checking recovered changes for PR #$($pr.Number)"
    }

    git -C $worktreePath commit -m "Recovered PR #$($pr.Number): $($pr.Title)"
    Assert-LastCommand "Committing recovered changes for PR #$($pr.Number)"

    git --git-dir=$RepoPath worktree remove $worktreePath
    Assert-LastCommand "Removing temporary worktree for PR #$($pr.Number)"
    Remove-Item -LiteralPath $patchPath

    Write-Host "`nPR #$($pr.Number): $($pr.Title)"
    Write-Host "  Target:             $($pr.Base)"
    Write-Host "  Recovered boundary: $recoveredBoundary"
    Write-Host "  Matching target:    $matchingTargetCommit"
    Write-Host "  Recovered commits:  $($commitsToRecover.Count) (first-parent, including merges)"
    git --git-dir=$RepoPath diff --stat "$baseRef...$archiveRef"
    Assert-LastCommand "Comparing archive branch for PR #$($pr.Number)"
}

Remove-Item -LiteralPath $temporaryRoot -Recurse

$matches = @(Get-ReplacementHistoryMatches -Revision "--all" -SearchValues $replacementSearchValues)

if ($matches.Count -gt 0) {
    Write-Host "`nOld values from the replacement file still found:"
    $matches
    throw "Verification failed. Nothing was pushed."
}

Write-Host "`nVerification passed: no old values from the replacement file were found."

$confirmation = Read-Host "Type PUSH to publish $archiveBranch"
if ($confirmation -cne "PUSH") {
    Write-Host "Archive branches remain local only."
    exit 0
}

foreach ($pr in $metadata) {
    $branchName = Get-RecoveredBranchName -Number $pr.Number -Title $pr.Title
    $source = "refs/heads/$branchName"

    git --git-dir=$RepoPath show-ref --verify --quiet $source
    if ($LASTEXITCODE -eq 1) {
        Write-Host "Skipping PR #$($pr.Number), which produced no archive branch."
        continue
    }
    Assert-LastCommand "Checking archive branch for PR #$($pr.Number)"

    Write-Host "Publishing archive branch for PR #$($pr.Number)..."
    git --git-dir=$RepoPath push origin "${source}:${source}"
    Assert-LastCommand "Publishing PR #$($pr.Number)"
}

Write-Host "`nDone. Review the recovered PR branch, then close the old PR."
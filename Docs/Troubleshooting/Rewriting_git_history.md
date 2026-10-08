# Rewriting Git history

Use this procedure to replace sensitive or incorrect text, or delete a file from every reachable commit, branch, and tag of a repository.

History rewriting changes commit IDs. Coordinate the operation with all contributors, stop pushes while it runs, and keep a backup until the result has been verified.

Both operations use the same preparation, publishing, fork-update, and existing-clone migration steps below. Only the filtering command and verification differ.

## Prerequisites

Install Python and [`git-filter-repo`](https://github.com/newren/git-filter-repo):

```powershell
python -m pip install --user git-filter-repo
```

For text replacement, create `replacements.txt` outside the repository or ensure that it will never be committed. Each literal replacement uses this format:

```text
old value==>replacement value
```

Use the exact same filters, options, and replacement file (if applicable) for the main repository and all forks. Consistent filtering is necessary to preserve shared rewritten ancestry; verify comparability afterward.

## Preserve current work

Before rewriting:

1. Stop pushes to the repository.
2. Commit and push every branch that must be retained, including WIP branches.
3. Save uncommitted and untracked files separately.
4. Keep a backup of the original clone.
5. Record the repository URL.

Local-only branches are not present in a new mirror clone. Push them first or handle them separately afterward.

## Rewrite the repository

Create a fresh mirror clone. Do not run the initial rewrite in a normal working clone.

```powershell
Set-Location C:\temp
git clone --mirror https://github.com/OWNER/REPOSITORY.git cleanup.git
Set-Location cleanup.git
```

### Option A: Replace text

Apply the replacements to all local refs:

```powershell
python -m git_filter_repo `
  --replace-text C:\path\to\replacements.txt
```

### Option B: Delete a file

Remove a file from all local history using its repository-relative path with forward slashes:

```powershell
python -m git_filter_repo `
  --path "path/to/file.ext" `
  --invert-paths
```

This deletes the file from rewritten commits, not just the current branch. No `replacements.txt` is needed for file removal alone.

Path filtering does not follow renames automatically. Include every historical path in the same command if the file was renamed or moved:

```powershell
python -m git_filter_repo `
  --path "path/to/file.ext" `
  --path "previous/path/file.ext" `
  --invert-paths
```

If both operations are needed, add `--replace-text C:\path\to\replacements.txt` to the file-removal command and verify both results. Deleting a file does not remove copies of its contents from other files or commit messages. If it contained credentials, revoke or rotate them as well.

### Restore the remote

`git-filter-repo` removes `origin` as a safety measure. Restore it:

```powershell
git remote add origin https://github.com/OWNER/REPOSITORY.git
```

## Verify before pushing

For text replacement, search all rewritten refs for the old text:

```powershell
git log --all -S"old value" --oneline
```

For file removal, check each historical path:

```powershell
git log --all -- "path/to/file.ext" "previous/path/file.ext"
```

Expected result for either check: no output. Run both checks if you combined the operations.

Inspect important branches and compare their changes before publishing the rewrite.

## Publish rewritten branches and tags

Force-push every branch and tag from the cleaned mirror:

```powershell
git push --force --all origin
git push --force --tags origin
```

Protected branches or repository rules may need to allow force-pushes temporarily.

Verify that local and remote tag IDs match. A simple check for one tag is:

```powershell
git show-ref --tags TAG_NAME
git ls-remote --tags origin TAG_NAME
```

For a complete verification, compare every `refs/tags/*` object ID. Matching tag names alone are insufficient because rewritten tags retain their names but point to new objects.

## Update forks

Every fork that should remain comparable must run the same mirror-clone procedure with identical filters: the same `replacements.txt` for text replacement, the same historical paths with `--invert-paths` for file removal, or both. Then force-push all fork branches and tags.

After rewriting a fork, compare an important branch with the rewritten upstream:

```powershell
git remote add upstream https://github.com/OWNER/REPOSITORY.git
git fetch upstream "+refs/heads/*:refs/remotes/upstream/*"

git diff --stat upstream/BASE_BRANCH...refs/heads/FEATURE_BRANCH
git log --oneline upstream/BASE_BRANCH..refs/heads/FEATURE_BRANCH
```

Only the feature branch's actual changes should appear.

Run the companion `recover-prs.ps1` script from the designated temporary directory.
It requires `replacements.txt` in that directory and checks that the file exists
before accessing the mirror. Specify the GitHub repository as `OWNER/REPOSITORY`;
the mirror defaults to `cleanup.git` relative to the calling directory.

```powershell
Set-Location C:\temp
.\recover-prs.ps1 -GitHubRepository "ExFace/Core" -PullRequestNumber 948
```

Use `-RepoPath "another-cleanup.git"` for a differently named mirror, or supply an
absolute path. Omit `-PullRequestNumber` to select an open PR interactively.

The script reads all original literal values from `replacements.txt` for history
verification; no replacement-file or old-text parameter is needed.
It supports `old value==>replacement value`, optional `literal:` prefixes,
and values without `==>` (which `git-filter-repo` replaces with its default text).
Regex and glob rules are rejected before recovery because the script's history
checks use literal matching. This recovery script handles text replacements only;
it does not apply or verify file-removal history filters.

## Replace working clones

These steps apply equally to text replacement and file removal: both change commit IDs and invalidate the old ancestry for affected history. Merely pulling the rewritten branches or deleting the file in an old clone is not sufficient.

The safest option is to replace every old working clone:

```powershell
Rename-Item REPOSITORY REPOSITORY-before-history-rewrite
git clone https://github.com/OWNER/REPOSITORY.git REPOSITORY
```

Restore saved uncommitted files manually. Do not merge or push branches directly from the old clone.

If an existing clone must be retained, first preserve all unique local commits. Then move its branches onto the rewritten history, replay the unique commits, and replace stale tags explicitly:

```powershell
git fetch origin --prune "+refs/tags/*:refs/tags/*"
```

Do not reset a branch until its unique local commits and uncommitted files have been backed up.

## Pull requests and releases

Pull requests from unrewritten forks can show thousands of unrelated changes because their old commits no longer share ancestry with the rewritten base. Rewrite the fork or recreate the change on a clean branch. Never merge the old branch into rewritten history.

GitHub releases normally continue to reference rewritten tags by name. Check release descriptions and manually uploaded assets separately; Git history rewriting does not modify them.

## Final checks

- All required branches were force-pushed.
- All tags were force-pushed and their object IDs match the remote.
- Text searches return no results for every replaced value, and path-history checks return no results for every deleted file's historical paths, as applicable.
- Forks used for pull requests were rewritten identically.
- Contributors replaced or repaired old working clones.
- Old branches are never pushed back into the rewritten repository.
- GitHub Support was contacted if cached pages, pull-request refs, forks, or generated archives still expose sensitive content.

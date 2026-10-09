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

## Turn pull requests into branches

Use the companion [recover-prs.ps1](recover-prs.ps1) script to preserve an open
GitHub PR as a branch in the main repository after a text-replacement history
rewrite. This is useful when the PR's fork still has old ancestry and its changes
need to be recovered onto comparable history. The script processes one open PR
per run; it does not rewrite the fork or update the original PR.

### Prerequisites

- Use a cleaned bare mirror containing the rewritten PR base branch and an
  `origin` remote pointing to the GitHub repository whose PRs you are recovering.
- Make Git and `git filter-repo` available on `PATH`. The script invokes
  `git filter-repo`, not `python -m git_filter_repo`.
- Configure Git commit identity and credentials with permission to push branches
  to `origin`. PR discovery uses the GitHub API without authentication; private
  repositories are not supported by the script as written, and API rate limits apply.
- Put the same `replacements.txt` used for the original rewrite in the calling
  directory. It is required even when reusing an earlier recovery attempt.
- Keep a backup of the mirror. The script filters history in that mirror again
  when importing a PR, and a confirmed rebuild deletes the earlier local attempt.

Run the script from the designated temporary directory. It checks for
`replacements.txt` there before accessing the mirror. Copy the companion script
there first, or invoke it by its full path while keeping that working directory.

```powershell
Set-Location C:\temp
.\recover-prs.ps1 -GitHubRepository "ExFace/Core" -PullRequestNumber 948
```

### Command-line options

| Option | Default | Meaning |
| --- | --- | --- |
| `-GitHubRepository "OWNER/REPOSITORY"` | Required | GitHub repository whose open PRs are listed. Use an owner/repository pair, not a URL; this must correspond to the mirror's `origin`. |
| `-RepoPath "cleanup.git"` | `cleanup.git` | Path to the bare mirror. Relative paths are resolved against the calling directory; absolute paths are also accepted. |
| `-PullRequestNumber 948` | `0` | Select one open PR by number. Omit it or pass `0` to list open PRs and enter a number interactively. Closed and merged PRs cannot be selected. |

For a differently named mirror and interactive PR selection:

```powershell
Set-Location C:\temp
.\recover-prs.ps1 -GitHubRepository "ExFace/Core" -RepoPath "another-cleanup.git"
```

There are no parameters for a replacement-file path, old text, output branch
name, processing all PRs, or unattended publishing. Specifying a PR number skips
only the selection prompt, not the confirmation prompts.

### Recovery and confirmation prompts

The script fetches the selected PR head into `recovered/pr-<number>` and applies
the text replacements. It finds a commit in the rewritten base history with the
same tree as a commit on the recovered PR's first-parent history, then replays
the subsequent non-merge first-parent changes on that matching base commit.
The result is a single new commit named `Recovered PR #<number>: <title>` on
`pr/<number>-<title-slug>`. The slug is derived from the title automatically;
original commit IDs, individual commit messages, and authorship are not preserved.
Merge commits are excluded, so review the result for missing merge-only changes.

Prompts require the exact uppercase response shown:

| Response | When offered | Effect |
| --- | --- | --- |
| `IMPORT` | Before fetching the PR head | Imports the selected PR into the mirror. Any other response cancels. |
| `FILTER` | After importing | Rewrites history using `replacements.txt`. Any other response cancels, leaving the imported history unfiltered in the mirror. Do not publish it. |
| `REUSE` | An existing filtered `recovered/pr-<number>` passes the history checks | Reuses that local ref without fetching or filtering again. Any other response proceeds to the import confirmation. |
| `REBUILD` | An earlier local output branch exists | Discards and rebuilds the local attempt, including forcibly removing its attached worktree if present. Back up any work in that worktree first. |
| `PUSH` | Recovery is verified, or a completed local output branch is found | Pushes only the recovered output branch to `origin`. Any other response leaves a completed branch local; `REBUILD` is also available for a previously completed attempt. |

If the generated branch already exists on `origin`, the script exits without
changing it. A remote legacy branch named `archive/pr-<number>` blocks recovery
until it is renamed or deleted. A completed local legacy branch can be renamed
automatically to the new naming scheme during recovery.

Review the displayed comparison with the PR base before confirming `PUSH`.
The script stops if it cannot find a tree-equivalent base commit, cannot apply
the recovered changes, or detects old replacement values in its history checks.
It also skips PRs with no eligible non-merge first-parent commits. After publishing,
review the branch on GitHub, open a replacement PR if needed, and close the old PR.

### Replacement rules and limitations

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

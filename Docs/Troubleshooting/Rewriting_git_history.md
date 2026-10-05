# Rewriting Git history with `replacements.txt`

Use this procedure to replace sensitive or incorrect text in every reachable commit, branch, and tag of a repository.

History rewriting changes commit IDs. Coordinate the operation with all contributors, stop pushes while it runs, and keep a backup until the result has been verified.

## Prerequisites

Install Python and [`git-filter-repo`](https://github.com/newren/git-filter-repo):

```powershell
python -m pip install --user git-filter-repo
```

Create `replacements.txt` outside the repository or ensure that it will never be committed. Each literal replacement uses this format:

```text
old value==>replacement value
```

Use the exact same replacement file for the main repository and all forks. This makes rewriting deterministic: commits shared before the rewrite receive the same new IDs.

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

Apply the replacements to all local refs:

```powershell
python -m git_filter_repo `
  --replace-text C:\path\to\replacements.txt
```

`git-filter-repo` removes `origin` as a safety measure. Restore it:

```powershell
git remote add origin https://github.com/OWNER/REPOSITORY.git
```

## Verify before pushing

Search all rewritten refs for the old text:

```powershell
git log --all -S"old value" --oneline
```

Expected result: no output.

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

Every fork that should remain comparable must run the same mirror-clone procedure with the exact same `replacements.txt`, then force-push all fork branches and tags.

After rewriting a fork, compare an important branch with the rewritten upstream:

```powershell
git remote add upstream https://github.com/OWNER/REPOSITORY.git
git fetch upstream "+refs/heads/*:refs/remotes/upstream/*"

git diff --stat upstream/BASE_BRANCH...refs/heads/FEATURE_BRANCH
git log --oneline upstream/BASE_BRANCH..refs/heads/FEATURE_BRANCH
```

Only the feature branch's actual changes should appear.

The companion `recover-prs.ps1` script reads all original literal values from its
`-ReplacementFile` for history verification; no separate old-text parameter is
needed. It supports `old value==>replacement value`, optional `literal:` prefixes,
and values without `==>` (which `git-filter-repo` replaces with its default text).
Regex and glob rules are rejected before recovery because the script's history
checks use literal matching.

## Replace working clones

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
- Searches for every removed value return no results in rewritten refs.
- Forks used for pull requests were rewritten identically.
- Contributors replaced or repaired old working clones.
- Old branches are never pushed back into the rewritten repository.
- GitHub Support was contacted if cached pages, pull-request refs, forks, or generated archives still expose sensitive content.

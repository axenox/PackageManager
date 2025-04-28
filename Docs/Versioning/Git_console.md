# Built-in git console

There is a built-in git console available in `Administration > Metamodel > Apps`. This allows to perform git commands upon an app after the export-folder of the app was properly set up - see below.

## Configuring git access

### What user is PHP running under?

The user, that runs PHP (further referred to as the PHP user) will need access to various folder in order to use the git commands. To find out, which user it is, go to `Administration > Console` and type `whoami`.

### Git configuration

#### What config applies to the git console?

The console runs as the OS user running the web server. To check the current git configuraion open the git console an run `git config --list --show-origin --show-scope`. This will show you all git options applicable to the workbench user. Depending on the configuration of your web server, the options and the location of the config files may be very different, but they will be visible in the output of this command.

Here is where to find the system git config on different servers. You can create a file called `.gitconfig` here in order to add global config options:

- Windows + IIS: `C:\Windows\System32\config\systemprofile`
- Windows + Apache running as localadmin: `C:\Program Files\Git\etc` (the file will be already here, edit it)
- Windows + Apache running as a specific user: `C:\Users\<php-user-username>`

#### General requirements & recommendations

These global options are recommended. Use `git config --global core.autocrlf input` or similar to set them.

- `core.autocrlf = input` to avoid strange diffs due to different line endings locally and on the remote
- `user.name = <server name>` helps avoid warnings about missing global user info when committing. The actual name is not important, it will be overwritten by the current user on every commit anyway.
- `user-email = <email of user responsible for this server>`

Corresponding git commands:

```
git config --global core.autocrlf input
git config --global user.name "Mustermann, Max"
git config --global user.email "max.mustermann@domain.com"
```

#### Using local repos created by another user

If you have cloned a repo with a regular "human" user, the git console will not able to use it as-is because repos of other users are not considered safe by default. You can explicitly disable this by adding the following config option:

- `safe.directory = *` allows git to work with other users repos

#### Example global config

In short, you can place the following in the users-scope or system-scope .gitconfig file. The locations of the respective files are visible in the output of the command above.

```
[safe]
	directory = *
[user]
	name = <computer network name>
	email = <email of responsible person>
[credential "https://git.yourdomain.com"]
	provider = generic
[credential]
    helper = 

```

### Permissions for the vendor folder

Make sure, the user running PHP has full access to the folder of the app - including the `.git` subfolder.

## Sync a local app with a remote git repo

### Create a new git repo for exported app

1. Go to your git server (e.g. Gitlab or Github) and create a new repo/project
2. Add an access key with the rights to read and write the repo
4. On your workbench UI go to Administration > Metamodel > Apps
5. Find your app and press `Export model`
6. Press `Git console`
7. Perform the following commands

```
git init --initial-branch=main
git remote add origin <url to git repo>
git add .
git commit -m "Initial commit"
git push --set-upstream origin main
```

### Connect to existing git repo via git Console

Assuming you already have a remote git repository for your app (e.g. on GitHub), follow these steps to put your locally designed app into that repo. Don't worry if the repo is not empty, you will get a diff with the local app at the end.

1. Go to Administration > Apps
2. Export the app (let's say its alias is `my.App`)
3. Press `git Console` button
4. Run the following commands
	- `git init` to make the folder a git repo
	- `git remote add origin <Remote repository URL preferrably with access token>` to connect it to the remote git server. A repo URL with an access token looks like this: `https://username:CcVuWsM6xrGcsdfsdfzkex@git.yourdomain.com/vendor/package.git`
	- `git clean -f *` to remove the exported files again in preparation to pull from the remote
	- `git pull` to get the current remotes
	- `git switch main` to pull the `main` branch from the remote. Replace the branch name as needed.
5. Export the app again to overwrite files pulled from the remote with the local state of the app
6. Open the `git Console` again to get a diff.
7. Commit/push changes and start working

**Note:** currently the git console will always authenticate as the same user, but each commit will still have the correct author - the workbench user performing it. It is recommended to add a separate access-token to the repo for the specific machine running the git console. This is more explicit, than associating the git console with an access token of some git user. On GitLab you can do it via Repos Setting > Project Access Tokens.

### Use a previously cloned app folder

If you created the app folder by cloning an existing repo, you will need to add the access token manually: head to the `.git` subfolder, open `.git/config` and change the URL in the `[remote "origin"]` section to contain the username and access-key of a user, that can read/write the remote repo - see above.

## Troubleshooting git

See common [Troubleshooting Git](https://github.com/ExFace/Core/blob/1.x-dev/Docs/Troubleshooting.md) page in the COre app.


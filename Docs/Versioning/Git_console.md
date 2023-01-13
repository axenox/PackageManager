# Built-in git console

There is a built-in git console available in `Administration > Metamodel > Apps`. This allows to perform git commands upon an app after the export-folder of the app was properly set up - see below.

## Configuring git access

### What user is PHP running under?

The user, that runs PHP (further referred to as the PHP user) will need access to various folder in order to use the git commands. To find out, which user it is, go to `Administration > Console` and type `whoami`.

### Global git config 

Make sure the global git config of the PHP user has the following configuration:

```
[safe]
	directory = *
[user]
	name = username
[credential "https://git.yourdomain.com"]
	provider = generic
[credential]
    helper = 

```

Here is where to find the global git config on different servers:

- Windows + IIS: 
	- Create a file called `.gitconfig` in `C:\Windows\System32\config\systemprofile`
	- Copy the configuration from above into the file
- Windows + Apache
	- Create a file called `.gitconfig` in `C:\Users\<php-user-username>`
	- Copy the configuration from above into the file

### Permissions for the vendor folder

Make sure, the user running PHP has full access to the folder of the app - including the `.git` subfolder.

## Sync a local app with a remote git repo

### Connect to git via git Console

Assuming you already have a remote git repository for your app (e.g. on GitHub), follow these steps to put your locally designed app into that repo. Don't worry if the repo is not empty, you will get a diff with the local app at the end.

1. Go to Administration > Apps
2. Export the app (let's say its alias is `my.App`)
3. Press `git Console` button
4. Run the following commands
	- `git init` to make the folder a git repo
	- `git remote add origin <Remote repository URL preferrably with access token>` to connect it to the remote git server. A repo URL with an access token looks like this: `https://username:CcVuWsM6xrGcsdfsdfzkex@git.yourdomain.com/vendor/package.git`
	- `git clean -f *` to remove the exported files again in preparation to pull from the remote
	- `git switch main` to pull the `main` branch from the remote. Replace the branch name as needed.
5. Export the app again to overwrite files pulled from the remote with the local state of the app
6. Open the `git Console` again to get a diff.
7. Commit/push changes and start working

**Note:** currently the git console will always authenticate as the same user, but each commit will still have the correct author - the workbench user performing it. It is recommended to add a separate access-token to the repo for the specific machine running the git console. This is more explicit, than associating the git console with an access token of some git user. On GitLab you can do it via Repos Setting > Project Access Tokens.

### Use a previously cloned app folder

If you created the app folder by cloning an existing repo, you will need to add the access token manually: head to the `.git` subfolder, open `.git/config` and change the URL in the `[remote "origin"]` section to contain the username and access-key of a user, that can read/write the remote repo - see above.
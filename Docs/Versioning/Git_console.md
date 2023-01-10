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

### Local repository configuration

Make sure, the app folder (e.g. `vendor/my/app`) is a git-repo. Either clone one before exporting the app or perform the following commands:

```
# Initialize the local directory as a Git repository.
git init

# Add remote origin
git remote add origin <Remote repository URL preferrably with access token>
```

A repo URL with an access token looks like: `https://username:CcVuWsM6xrGcsdfsdfzkex@git.yourdomain.com/vendor/package.git`

If you cloned an existing repo from the command line, you will need to add the access token manually: head to the `.git` subfolder, open `.git/config` and change the URL in the `[remote "origin"]` section to contain the username and access-key of a user, that can read/write the remote repo - see above.

**Note:** currently the git console will always authenticate as the same user, but each commit will still have the correct author - the workbench user performing it. It is recommended to add a separate access-token to the repo for the specific machine running the git console. This is more explicit, than associating the git console with an access token of some git user. On GitLab you can do it via Repos Setting > Project Access Tokens.

### Permissions for the vendor folder

Make sure, the user running PHP has full access to the folder of the app - including the `.git` subfolder.
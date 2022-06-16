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

In order to pull/push changes for an app directly to a remote git repository, you will need to pull the repository first - for example using the command promt of the server running the workbench and providing your personal git user (don't worry, other workbench users will not be able to use your personal credentials!).

Now that the app folder has a `.git` subfolder with the corresponding configuration, open `.git/config` and change the URL in the `[remote "origin"]` section to contain the username and access-key of a user, that can read/write the remote repo. Like this: `https://username:CcVuWsM6xrGcsdfsdfzkex@git.yourdomain.com/vendor/package.git`

Note: currently the git console will always authenticate as the same user, but each commit will still have the correct author - the workbench user performing it. 

### Permissions for the vendor folder

Make sure, the user running PHP has full access to the folder of the app - including the `.git` subfolder.
# phgram-manager
A telegram bot for managing files in your server

## Installation
Download `manager.php` into your server and set the bot webhook to it in the following format: `https://domain.com/manager.php?token=BOT_TOKEN&admin=YOUR_ID` (replacing BOT\_TOKEN and YOUR\_ID to its values).
YOUR\_ID is necessary for the first execution. The script will create `manager.db` (to save the ids of allowed users) and save your id. Then you can add other users using `/sql insert into users(id) values (USER_ID)`

You'll not be able to use MadelineProto features until you edit manager/madeline/settings.ini, changing API\_ID and API\_HASH to your custom values (from my.telegram.org).

## Requirements
`manager.php` automatically downloads the requirements when it need them, so don't worry about download and installing anything. phgram-manager uses:
- [MadelineProto](https://github.com/danog/MadelineProto) (for downloading and uploading files up to 1,5GB and other nice features)
- [getID3](https://github.com/JamesHeinrich/getID3) (for getting the duration of videos)
- Files inside phgram.phar (not phgram.phar) from [phgram](https://github.com/usernein/phgram)

## How to use
### Commands
_Note: the parameters described below are following the format: [optional] {mandatory}._

 * `/list [path]` - sends a message with a inline keyboard which you can use to navigate through your files and folders
 * `/add` - in reply to a file, you'll add it in the current working directory
 * `/add [name]` - in reply to a file, you'll add it as the specified path or name
 * `/add [path]/` - in reply to a file, you'll add it under the specified path
 * `/add {path} {content}` -- you'll create a new file with the specified content
 * `/get {path}` - download a file
 * `/del {path}` - delete a file
 * `/sql {sql query}` - execute a sql query on manager.db and return a JSON with all result lines
 * `/ev {php code}` - execute a php code inside manager.php (with php function eval) and return the contents it echoed
 * `/zip {path}` - you'll receive the zipped content of the specified path
 * `/unzip {path}/` - in reply to a zip file, you'll extract its files to the specified path

_NOTE: /add (in reply to file), /get, /del and /zip are obsolete, since you can do it all using the keyboard menu of /list._

### Allowing and disallowing users
`manager.db` is a SQLite3 database with the following schema:
```
CREATE TABLE users (
    key INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    id INTEGER UNIQUE NOT NULL,
    upload_path TEXT NULL DEFAULT '..',
    auto_upload INTEGER NULL DEFAULT 0,
    php_check INTEGER NULL DEFAULT 0,
    timezone TEXT NULL DEFAULT 'UTC',
    waiting_for TEXT NULL DEFAULT NULL,
    waiting_param TEXT NULL DEFAULT NULL,
    waiting_back TEXT NULL DEFAULT NULL,
	show_rmdir INTEGER NULL DEFAULT 0
);
```

To allow users to use the bot, use the following command (change USER\_ID to the id of the user you want to allow)
`/sql INSERT INTO users(id) VALUES (USER_ID)`
To disallow:
`/sql DELETE FROM users WHERE id=USER_ID`

You can active the "show_rmdir" feature (a dangerous button in /list for recursively deleting the entire current directory and its contents) for a user with the command below:
`/sql UPDATE users SET show_rmdir=1 WHERE id=USER_ID`

## Find us
* Telegram channel: [@phgrammanager](https://t.me/phgrammanager)
* Developer (telegram): [@usernein](https://t.me/usernein)
* Logo by [@AlissonLauffer](https://t.me/AlissonLauffer)
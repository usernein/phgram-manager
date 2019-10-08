# phgram-manager
Bot for telegram that manage files in your server

## Installation
Download `manager.php` into your server and set the bot webhook to it in the following format:
`https://domain.com/manager.php?token=BOT\_TOKEN&admin=YOUR\_ID`
Replacing BOT\_TOKEN and YOUR\_ID to its values (you know what it mean). YOUR\_ID is necessary to the first execution. The script will create `manager.db` (to save the ids of allowed users) and save your id. Then you can add other users using `/sql insert into users(id) values (USER\_ID)`

## Requirements
`manager.php` automatically downloads the requirements when it need them, so don't worry about download and installing. phgram-manager uses:
- MadelineProto (for downloading and uploading files up to 1,5GB and other features)
- FFMpeg (for getting the duration and thumbnails of videos)
- Files of phgram.phar (not phgram.phar)

## How to use
Soon u.u
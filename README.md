snapshot-backup.php
==========

CLI script used for automating backup of DigitalOcean volumes.

## Usage instructions

1. Change configuration values to match your setup.
2. Use 'crontab -e' to configure script to run:
```
# Backup our data volume using a snapshot each day at 6am
0 6 * * * /usr/bin/php /root/snapshot-backup.php
```
Note that the script will clean up(delete) any snapshots(with the given prefix) that does not fit within any of the specified backup slots.
The standard configuration will create 4 different backups if triggered at the same time each day:
* One that is less than 24 hours old
* One that is 1 - 2 days old
* One that is 2 - 7 days old
* One that is 8 - 14 days old

## License

(The MIT License)

Copyright (c) 2016 Joubel AS

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

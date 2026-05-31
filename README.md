# Large SQL Importer

A single PHP file that imports large database dumps without hitting PHP upload limits or script timeouts. Drop it on any PHP site, open it in your browser, type your database details, and import. It works on shared hosting where phpMyAdmin gives up.

It streams the file instead of loading it into memory, so file size is not really a limit. If the import stops for any reason, you can resume it from where it left off.

## Requirements

- PHP 8.0 or newer with the mysqli extension (both are standard).
- MySQL or MariaDB.
- The bzip2 extension if you want to import `.bz2` files, and ZipArchive for `.zip` files. Plain `.sql` and `.sql.gz` need nothing extra.

## Installation

There is nothing to install. Copy `index.php` into a folder on your site and open it in a browser, for example `https://yoursite.com/large-databases-upload/`.

The tool creates two folders next to itself on first run: `uploads` for the files you import and `.state` for resume data. Make sure the folder is writable.

## Usage

1. Enter your database host, username, password, and database name, then click Test Connection. If a `wp-config.php` or `.env` file sits nearby, the fields are filled in for you.
2. Upload a dump by dragging it onto the box, or pick a file that is already on the server.
3. Choose your options (see below).
4. Click Start Import. Watch the progress bar, statement count, speed, and estimated time.

If the import is interrupted, the file shows up as resumable. Select it and click Resume to continue from the last saved point.

## Supported files

- `.sql`
- `.sql.gz`
- `.sql.bz2`
- `.zip` (the first `.sql` inside the archive is used)

## Options

**Import into the connected database.** Many dumps start with `CREATE DATABASE` and `USE somename`, which sends the data into a database you did not choose. With this on, those lines are ignored and everything goes into the database you connected to. On by default.

**Skip statements that error.** Keeps going when a statement fails instead of stopping. Skipped statements are listed in the log with their line number.

**Keep FK and unique checks on.** Off by default. Leaving it off makes imports faster and avoids ordering errors. Turn it on if you need the checks enforced during import.

**Dry run.** Reads the whole file and reports what it would do without changing anything. Good for checking a dump before you commit to it.

**Skip tables that already exist.** Leaves your current tables alone and only imports the ones that are missing.

**Import only these tables.** Give a comma separated list to import just those tables and ignore the rest.

**Preview.** Shows the first statements and the tables found in the dump, so you know what you are about to run.

**Pre and post SQL.** Optional SQL to run before the import starts and after it finishes, for example turning a setting off first or rebuilding indexes at the end.

## A note on speed

Old dumps that write one row per `INSERT` are slow to import. The tool merges those into multi row inserts on the fly, which is much faster. Modern dumps are already in that shape, so nothing changes for them.

## Security

This tool has no login. Anyone who can open the URL can read and overwrite your entire database. Use it, then delete the file. Do not leave it on a live or public server.

## Credits

Created by [Olakunlevpn](https://github.com/olakunlevpn). Source and issues: [large-sql-importer](https://github.com/olakunlevpn/large-sql-importer).

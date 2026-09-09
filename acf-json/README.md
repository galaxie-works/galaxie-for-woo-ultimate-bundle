# acf-json

ACF field groups, as code.

`Galaxie\Woo\Integrations\Acf` adds this directory to `acf/settings/load_json`,
so every group whose `.json` file lives here is available on every site the
plugin is deployed to. One file per group, named after its key
(`group_xxxxxxxxxxxxx.json`) — that is the filename ACF itself writes, and ACF
matches groups to files by key, not by name.

## Why the definitions live here

They used to live only in the production database. Nothing declared them,
nothing exported them, and the staging site had none at all: an environment got
the fields if, and only if, somebody remembered to import them by hand. A field
group in a database is invisible to code review, absent from the diff of the
change that depends on it, and gone the moment a site is rebuilt from scratch.

Here it is a file: reviewable, versioned alongside the code that reads it, and
identical everywhere.

## Adding or changing a group

1. Edit the group in WP admin on a site where that is safe.
2. **ACF → Tools → Export field groups**, select the group, *Export As JSON*.
3. Drop the file in this directory and commit it.
4. Deploy. ACF picks it up; sites that already have a database copy show it as
   available to sync under **ACF → Field Groups → Sync**.

`save_json` is deliberately **not** registered — see the comment in
`src/Integrations/Acf.php`. In short: it would write admin edits into the plugin
folder on the server, where the next deploy silently overwrites them.

## Values are not definitions

This directory holds the *shape* of the fields. The values on each product are
content and live in the database like any other post meta; nothing here writes
them.

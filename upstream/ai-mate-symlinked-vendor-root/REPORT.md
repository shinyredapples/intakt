# [AI Mate] A symlinked `vendor` folder moves the project root, so no extension and no tool is found

Draft, not posted. Versions: `symfony/ai-mate` v1.0.0-BETA1, `symfony/ai-symfony-mate-extension` v1.0.0-BETA1, PHP 8.5.12.

## What happens

`bin/mate.php` takes the project root as `dirname(realpath($autoloadPath), 2)`. When the project's `vendor` folder is a symlink to a shared install (here a folder named `deps`), `realpath()` resolves it, and the root becomes the parent of that shared folder. Mate then looks for `vendor/composer/installed.json` and `mate/extensions.php` there, finds neither, and starts with no extension:

```
No server-info tool: the project root was taken from the real path of vendor
{
    "tools": [],
    "summary": {
        "total": 0
    }
}
[WARNING] Composer installed.json not found
```

`tools:call server-info` then fails with `Tool "server-info" not found`, and `discover` reports `No Mate extensions found.` The same project with a real `vendor` folder lists all tools.

## Expected

The root is the folder that holds the `vendor` entry used to start Mate (the autoloader path before symlinks are resolved), so a symlinked `vendor` behaves like a real one.

## Effect in Intakt

None in the shipped app, since Mate is a dev tool. A development install whose `vendor` is a symlink gets no Mate tools; `tests/Application/MateInvocationTest.php` fails there.

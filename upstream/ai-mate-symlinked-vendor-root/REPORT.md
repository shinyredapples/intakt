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

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-mate-symlinked-vendor-root`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-mate` a00fb617e382, `symfony/ai-symfony-mate-extension` 6d8aadc2dc76): `still fails  ai-mate-symlinked-vendor-root`, with no tool listed.
- **Existing issue or pull request:** none found. Closest candidate in `symfony/ai`: #1857 (open pull request, "Binary allow support for non-default vendor-dir to find project directory"), which replaces the same line `dirname(realpath($autoloadPath), 2)` with `InstalledVersions::getInstallPath('__root__')` for a `vendor-dir` outside the project. It is a different case, and likely does not fix this one, since Composer derives the root's install path from `__DIR__` in `vendor/composer/installed.php`, which PHP resolves through the symlink (not verified). Not the same bug: #2656, #2500 and #2657 (closed, project root of the Composer plugin), #2736 (open, location of the `mate/` folder), #2245 (closed, extension path traversal).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `label:Mate symlink`, `mate realpath`, `mate "project root"`, `label:Mate installed.json`.
- **Decision:** `ready to file`, naming #1857 as related.

## Symfony components
| Filesystem `mkdir`, `tempnam`, `chmod`, `appendToFile`, `rename` | a checked provider key is written to a new file with mode 0600 and renamed over `var/data/provider-key.json`, so it is never readable by others and a link at the path is replaced | done | #2257, #2330, `src/Core/Ai/Infrastructure/KeptProviderKeyFile.php` |

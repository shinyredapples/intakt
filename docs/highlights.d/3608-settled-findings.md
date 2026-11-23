## Symfony AI Store
| `StoreInterface::remove()` | forget a settled finding by its id, on the in-memory and SQLite stores | in progress | #3608, `src/Core/Memory/Infrastructure/StoreMemoryIndex.php`, `tests/Core/Memory/Infrastructure/SqliteMemoryStoreTest.php` |
| `VectorizerInterface` | embed a settled finding before its earlier entry is replaced, so a failing embedder keeps the stored entry | in progress | #3608, `src/Core/Memory/Infrastructure/StoreMemoryIndex.php`, `tests/Core/Memory/Infrastructure/StoreProjectMemoryTest.php` |

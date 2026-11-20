## Symfony components

| Messenger 8.2 `messenger:consume --concurrency` | the journey support starts one `messenger:consume async --concurrency=<n>` consumer with the options the image's entrypoint gives its async consumer, signals it, times its end and tells its workers' PIDs from reused ones; the journey that runs it and the image's own consumer still use one message at a time, since a concurrency above 1 needs `amphp/parallel`, which Intakt does not install yet | in progress | #2723 (part #3018), `tests/Journeys/Support/ConsumerProcess.php` |

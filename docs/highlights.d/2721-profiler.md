## Tooling
| FrameworkBundle profiler (`framework.profiler`) | measures a request (its database queries, its dispatched messages) in `dev` on every request and in `test` only after `enableProfiler()`; absent from `prod` and the image | in progress | #1882 (part #2721), `config/packages/framework.yaml`, `tests/Core/Dashboard/UseCase/ListReviewsQueriesTest.php` |

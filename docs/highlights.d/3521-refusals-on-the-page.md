## Symfony components

| HttpKernel error controller (`framework.error_controller`) | a dashboard form refused by its CSRF token or its round number (`FormIsRefused`, a `BadRequestHttpException`) shows its own plain sentence and how to retry on the error page, while every other 4xx keeps the generic line | done | #3521, `src/Core/Dashboard/UseCase/ShowError/ShowErrorController.php` |
| Mercure and Turbo Streams | a refused demo Play publishes a fixed sentence to the box of its own Play on `/demo`, so the person who pressed Play reads the reason instead of only the log | done | #3521, `src/Core/Dashboard/UseCase/PlayDemo/PlayDemoRunHandler.php` |

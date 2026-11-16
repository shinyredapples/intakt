## Symfony components

| HttpKernel error controller (`framework.error_controller`) | every uncaught exception, a missing page included, is answered by the dashboard's own controller in place of the framework's error page: inside the dashboard's shell with a link back to the board, a 5xx with a reference the log carries too, and as a plain page without project names for a `Host` that does not name the dashboard | done | #2185, `src/Core/Dashboard/UseCase/ShowError/ShowErrorController.php` |

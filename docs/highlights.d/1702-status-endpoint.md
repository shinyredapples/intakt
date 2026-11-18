## Symfony components

| HttpFoundation `JsonResponse::fromJsonString()` | the dashboard's `/status` endpoint answers the status read model's JSON as it was encoded, so the endpoint and `intakt:status` give the same bytes without a second encoding | in progress | T68 (#350, part #1702), `src/Core/Dashboard/UseCase/ShowStatus/ShowStatusController.php` |

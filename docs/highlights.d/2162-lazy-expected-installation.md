## Symfony components

| DependencyInjection lazy services (`lazy: true`) | the expected GitHub App installation is a lazy service, so the forge and the verdict publication are built on an install without GitHub App settings (the demo) and the settings are parsed only when a call first reaches GitHub | in progress | #2156 (part #2162), `config/services.yaml` |

# Setup

```
docker run -d --name intakt -v intakt:/data -v /var/run/docker.sock:/var/run/docker.sock -p 127.0.0.1:7780:7780 ghcr.io/shinyredapples/intakt
```

Then open the dashboard. The port is published on loopback only; phone access is opt-in (see [Dashboard](13-dashboard.md#only-the-owner)). Before anything else, the first-run wizard asks for the one-time setup code the first start printed to the container's log (`docker logs intakt`), then creates the owner secret or registers a passkey, which every change from the dashboard needs (a card's buttons and the admin area alike). Then it:
1. asks for the repository URL and detects the forge;
2. creates Intakt's own forge identity: on GitHub a GitHub App through the manifest flow (one confirmation by the owner; Intakt stores the App's private key, never a personal token), owned by the account that owns the repositories (a private App installs only there), installed only on the chosen repositories with the narrowest permissions (including the workflows permission, without which the forge refuses any push that touches CI files, even a merge of main into a branch); on Gitea a bot user with a scoped token. Before anything uses it, the wizard mints a token, reads the installation back and compares its repositories and permissions with the manifest, and offers to copy the private key to the owner's secret store. It then offers the forge's protection for the main branch (required status checks from that identity, signed commits, no force push, no deletion) and says what the owner may still bypass;
3. adds each vendor account, one or more per vendor: for a subscription, the vendor's automation token, which the owner creates with the vendor's CLI (the wizard shows the command, for example `claude setup-token`) and pastes in, or a device-flow login for a vendor without such a token; or an API key;
4. reads the project (see "New and existing projects") to propose the toolchain, job image and check command, and runs the check once; an empty repository goes to the foundation flow instead;
5. proposes defaults (one build slot, at most two built heads waiting for review, so builds cannot outrun reviews, label `intakt`), and writes one config file that the owner can edit later.

Podman works through its Docker-compatible socket. Rootless mode is the default where available.

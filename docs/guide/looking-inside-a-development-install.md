# Looking inside a development install

When you work on Intakt itself, you can ask a running development install what a request did, instead of guessing from the code.

## What a profile is

A development install (`APP_ENV=dev`) writes down a short **profile** of every request it answers: the database queries it ran, the messages it sent, how long it took and how much memory it used. To tell requests apart, it also keeps the request's method (such as `GET`), its address without any query (the part after a `?`) or fragment (the part after a `#`), left out entirely when the address cannot be read, and the status code of the answer. Nothing else goes into it: no headers, cookies, session, request body or environment variables, so no secret ends up in a profile.

The answer to each request names its profile in the `X-Debug-Token` header.

## Reading one

1. Start the install and send it a request at the address it listens on. With `php bin/instance`, the usual way to start one, that address is in `$INTAKT_INSTANCE_URL` (see the command below), so the request is `curl -si "$INTAKT_INSTANCE_URL/"`.
2. Copy the value of the `X-Debug-Token` header.
3. Run `vendor/bin/mate tools:call symfony-profiler-get --token=TOKEN --format=json`. It names the request and lists what was collected.

`vendor/bin/mate tools:call symfony-profiler-list --format=json` lists the latest profiles, newest first, when you did not keep the token.

`php bin/instance` keeps everything it writes, the profiles too, in a scratch folder that it removes when it stops, and tells the command you give it where that folder is. So run the request and Mate as that command:

```
APP_ENV=dev php bin/instance -- sh -c 'curl -si "$INTAKT_INSTANCE_URL/"; vendor/bin/mate tools:call symfony-profiler-list --format=json'
```

There is no toolbar and no `/_profiler` page; these two tools are the way to read a profile.

## What is not in the image

Mate and the profiler belong to the development install. The container image has neither: it is built without the development packages, and a production install saves no profiles at all.

For the other tools of the same kind (the services, the environment and the read-only console commands), see [Looking inside the development install with Mate](../user/README.md#looking-inside-the-development-install-with-mate).

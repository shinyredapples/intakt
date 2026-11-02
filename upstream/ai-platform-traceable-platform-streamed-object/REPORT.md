# [Platform] `TraceablePlatform` loses the object of a streamed structured-output call

Draft, not posted. Versions: `symfony/ai-platform` v1.0.0-BETA1, `symfony/ai-agent` v1.0.0-BETA1, `symfony/ai-bundle` v1.0.0-BETA1, PHP 8.5.12.

## What happens

With the `stream` option set, `TraceablePlatform::invoke()` replaces the platform's `DeferredResult` by one of its own, whose `PlainConverter` returns a new `StreamResult` that reads the original result through `asStream()`. The listeners the platform's result converters registered on the original stream (`PartialObjectStreamListener` for a `response_format`, the validator's hook on it, any listener a `ResultEvent` subscriber added) stay on the original stream: they still run while the deltas pass through, but the outer stream, the one the caller and the Agent's `Runner` see, has none of them.

So `DeferredResult::asObject()` on a traced call finds no `PartialObjectStreamListener` and throws `UnexpectedResultTypeException` ("expected ObjectResult, got StreamResult"), and the Agent's `Runner::streamedObjectResult()` finds none either and returns the turn as a `TextResult`, so `Execution::getResult()->getContent()` is the JSON text instead of the object. The bundle's `DebugCompilerPass` decorates every `ai.platform` service with `TraceablePlatform` when `kernel.debug` is on, so a streamed structured-output call that returns the object in production returns text in the dev environment.

`reproduce.php` invokes a mock platform with the `PlatformSubscriber`, `response_format` and `stream` set, once directly and once through `TraceablePlatform`, and prints:

```
the platform itself assembles the streamed object: approve
the traced platform loses it: Unexpected response type: expected "Symfony\AI\Platform\Result\ObjectResult", got "Symfony\AI\Platform\Result\StreamResult".
```

## Expected

A traced call behaves as the untraced one: `asObject()` returns the object and the Agent's execution ends in the `ObjectResult` the stream's listener assembled. The traceable stream could carry the original stream's listeners (`getListeners()`), or `TraceablePlatform` could listen on the original stream instead of wrapping it.

## Effect in Intakt

`src/Core/Review/Infrastructure/StrictVerdictStreamListener.php` puts the verdict it mapped on the stream's metadata when the stream completes, because metadata is copied from the original stream to the traced one, and `ReviewerPrompt::verdictIn()` reads it from the final result ahead of the content.

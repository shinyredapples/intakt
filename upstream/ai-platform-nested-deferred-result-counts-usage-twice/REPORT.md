# [Platform] A `DeferredResult` over another one's streamed answer counts the usage twice

Draft, not posted. Versions: `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What happens

`DeferredResult::getResult()` adds a `Metadata\StreamListener` and a `TokenUsage\StreamListener` to every `StreamResult` its converter returns, also to one that already carries them because another `DeferredResult` converted it first: a decorating platform that hands the decorated result's answer on (a failover layer, a layer that types the errors of a read) nests one `DeferredResult` in another. `TokenUsage\StreamListener` adds every usage delta to the event's metadata without checking whether an earlier listener already consumed it (`isDeltaSkipped()`), so each usage delta is added once per listener and the stream's `token_usage` is an aggregation that sums to twice what the provider reported.

`reproduce.php` converts a scripted stream that reports 40 prompt tokens once through a `DeferredResult` nested in another and prints:

```
the stream reported 40 prompt tokens once, the nested result reads 80
a nested deferred result counts the usage of a streamed answer twice
```

## Expected

A stream's usage is counted once however many `DeferredResult`s hand it on: `DeferredResult` could leave a stream alone that already has its listeners, or `TokenUsage\StreamListener` could ignore a delta an earlier listener skipped.

## Effect in Intakt

`src/Core/Ai/Infrastructure/AccountFailoverPlatform.php` types a key rejected when the answer is read through an error callback on the account's own result instead of a second `DeferredResult`, so a streamed round's usage is counted once.

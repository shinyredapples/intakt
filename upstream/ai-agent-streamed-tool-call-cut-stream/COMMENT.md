Comment for symfony/ai#2617 ([Agent] Expose each round's token usage as it happens, not only on a final result). Not posted: the owner approves this text first.

---

One more case this could cover: a streamed round that fails mid-stream. With a tool round (110 tokens) and then a round that streams its usage (120 tokens) and some text before `IncompleteStreamException`, `Execution::getMetadata()` holds no `token_usage` on `main` (e2b5e84) and 110 tokens at this head (4584b49): the cut round never reaches its `ModelResult`, although its `StreamResult` kept the 120 tokens through its `ErrorEvent`. Without the cut, both give 230 once.

```php
$calls = 0;
$platform = MockPlatformFactory::createPlatform(function () use (&$calls): StreamResult {
    return new StreamResult(1 === ++$calls
        ? (function () {
            yield new ToolCallComplete([new ToolCall('call-1', 'lookup')]);
            yield new TokenUsage(promptTokens: 100, completionTokens: 10, totalTokens: 110);
        })()
        : (function () {
            yield new TokenUsage(promptTokens: 120, totalTokens: 120);
            yield new TextDelta('The fact is');
            throw new IncompleteStreamException('cut');
        })());
});

$execution = (new Agent($platform, 'model', toolbox: new Toolbox([new LookupTool()])))
    ->call(new MessageBag(Message::ofUser('Tell me the fact.')), ['stream' => true]);

try {
    foreach ($execution->getContent() as $delta) {
    }
} catch (IncompleteStreamException) {
}

// expected 230 (110 + 120), each round once; null on main, 110 here
$execution->getMetadata()->get('token_usage');
```

`LookupTool` is any `#[AsTool('lookup', '...')]` class. Yielding the `ModelResult` of a stream that throws (from the `StreamResult`'s metadata, before the rethrow) would make the running total match what the provider billed.

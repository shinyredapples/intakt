## Symfony components

| Lock per finding key, not waited for, with Messenger's `RecoverableMessageHandlingException` | one escalation review of a recurring P1 per finding key at a time; a second delivery of the key finds the lock held, throws `EscalationBusy` and is brought back by the retry strategy, then reuses the verdict the first one kept | in progress | T61 (#3302, part #1759), `src/Core/Review/UseCase/EscalateRecurringP1/EscalateRecurringP1Handler.php` |

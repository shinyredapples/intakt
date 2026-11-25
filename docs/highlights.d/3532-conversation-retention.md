## Symfony components

| Scheduler | the conversation retention task (`#[AsSchedule('retention')]`, one `RecurringMessage` every ten minutes, inside the consumer's hour-long life), consumed beside the watcher by the image's scheduler process; it deletes reviewer conversations older than `retention.conversation_days` | in progress | #3532, `src/Core/Review/UseCase/ExpireConversations/RetentionSchedule.php` |

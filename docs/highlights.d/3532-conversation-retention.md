## Symfony components

| Scheduler | the conversation retention task (`#[AsSchedule('retention')]`, one hourly `RecurringMessage`), consumed beside the watcher by the image's scheduler process; it deletes reviewer conversations older than `retention.conversation_days` | in progress | #3532, `src/Core/Review/UseCase/ExpireConversations/RetentionSchedule.php` |

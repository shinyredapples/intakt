## Found in the beta
| Doctrine DBAL: a commit the database refuses leaves the driver's transaction open while DBAL counts it as ended, so `rollBack()` throws `NoActiveTransaction` | in progress | T56 (#2673, part #2961), `upstream/dbal-failed-commit-leaves-transaction-open/` | recheck needed before filing in doctrine/dbal: still fails on 4.6.x-dev and 5.0.x-dev, not yet searched for duplicates |

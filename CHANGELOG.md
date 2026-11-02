# Changelog

## 1.0.0 — 2026-11-02

First release.

### Added

- `RateLimiter` contract: `take()`, `giveBack()` and `clear()` on one window per key.
- Fixed windows, which close a set time after their first hit, and renewable windows (`renew: true`), which move their close with every hit taken.
- A refused take changes nothing: neither the count nor the close of the window.
- `limit` and `windowSeconds` accept `1..2_147_483_647`. Values outside this range throw `InvalidArgumentException` before the window is read or changed.
- `resetIn` rounds the remaining time up on both backends, so a live window reports at least `1` and suits `Retry-After`.
- `RedisRateLimiter` on phpredis. `take()` and `giveBack()` each run one Lua script, so concurrent processes never take the same last hit, and a count is written together with its new expiry.
- `InMemoryRateLimiter` with PSR-20 clock injection, for tests and single-process tools. Windows keep fractional seconds, expired keys are reclaimed periodically, and `purgeExpired()` reclaims them on demand.
- `RateLimiterException` for a store that fails: Redis command errors, invalid counters, unusable expiry, unexpected replies, failed deletes, and connections in a transaction or pipeline.

### Internal

- Separate unit and Redis integration suites. Docker Compose runs both, including the concurrency test.

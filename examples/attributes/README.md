# attributes

A payment gateway annotated with `#[Retry]`, `#[CircuitBreaker]` and
`#[Fallback]`, wrapped by the ProxyFactory. The fake acquirer stays down
for the first attempts and then recovers, so one run shows retries, the
circuit opening, fast rejections answered by the fallback, probing and the
recovery.

No docker needed, the failures are simulated in-process:

```bash
composer install
php examples/attributes/demo.php
```

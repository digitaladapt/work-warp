# WorkWarp

**Warp: the persistent surface that ephemeral commands cross.**

A workspace, terminal and filesystem for long-running agent tasks — work that
runs for days or weeks toward a single large goal, where each individual command
is short-lived, disposable and untrusted.

```
SESSION    durable policy + identity, may live months
   │
   ├── WORKSPACE   persistent named volume (the only thing that survives)
   │
   └── COMMAND     ephemeral container per command → output → gone
```

The name is the weaving family's: **Work** (the substance) + **Warp** (the tool).
The warp is the long, strong set of threads on a loom, held under tension, that
everything else works across — durable, stretched over time, crossed once by each
ephemeral command and then left alone.

- Container prefix: `ww-`
- Labels: `ww.session`, `ww.task`, `ww.ttl`, `ww.kind`

## Status

Planning. The design is in [`docs/design/PLAN.md`](docs/design/PLAN.md); nothing
is implemented yet.

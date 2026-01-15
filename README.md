# Relay Unified DB Fuzzer

This repository contains a deterministic workload generator that hammers the Relay unified database/shared cache extension. The tooling can:

- generate reproducible multi-worker payloads made up of common Redis-style operations
- drive those payloads either by forking multiple workers inside the current PHP process or by POSTing them to a PHP built-in server
- capture stdout/stderr/summary/core dumps for every run and automatically persist reproducers you can replay later

## Requirements

- PHP 8.2+ CLI with the [Relay](https://github.com/cachewerk/relay) extension enabled (via `php.ini` or compiled-in)
- `pcntl` and `posix` extensions when using `--mode=fork`
- Ability to launch the PHP built-in server (for `--mode=cli-server`)
- Composer (run `composer install` after cloning) and any system dependencies the Relay extension needs in order to reach your target datastore

```
composer install
```

## Entry Points

### `./fuzzer`
High-level orchestrator that runs `runner` repeatedly. You specify the total number of ops per worker (`--ops`), how many runs to execute (`--runs`), how many concurrent workers to launch (`--workers`), which execution mode to use (`fork` or `cli-server`), and optional constraints such as an allowlist of commands or a fixed seed. Results for every run are written under `.fuzzer/runs/run-xxxxx`.

- Failures automatically create a timestamped directory under `reproducers/` containing the payload, the exact runner command, logs, and any discovered core files.

### `./runner`
Single-run harness that generates a payload, executes it, and writes artifacts (`payload.bin`, `payload.json`, `summary.json`) into the directory named via `--artifact-dir`. This is what the outer `fuzzer` script uses to replay a saved seed quickly.

### `./gen_payload`
Utility that only emits a payload (JSON or PHP serialized format) without executing it. Helpful for offline inspection or crafting bespoke workloads.

## Options Overview

Common flags used by both `fuzzer` and `runner`:

| Flag | Description |
|------|-------------|
| `--php` | Path to the PHP binary to run worker code and (optionally) the built-in server |
| `--php-ini` | Optional php.ini that enables the Relay extension and configures your unified DB |
| `--ops` | Number of operations per worker in a single run |
| `--workers` | Number of worker payloads to build (default 1). Fork mode spawns this many processes; CLI server mode posts each worker sequentially |
| `--mode` | `fork` (pcntl workers) or `cli-server` (HTTP POST to `public/fuzz.php`) |
| `--commands` | Comma-separated allowlist. Values can be families (`string`, `hash`, `list`, `set`, `zset`) or individual commands (see below) |
| `--seed` | Deterministic seed. `fuzzer` will derive a per-run seed from it so failures reproduce |
| `--artifact-dir` | (runner only) Directory where payloads/summary/logs are written |
| `--redis-host` | Redis host (default `localhost`) |
| `--redis-port` | Redis port (default `6379`) |
| `--cli-server-hold` | Start CLI server and wait indefinitely after boot (skips sanity check and payloads) |

`fuzzer` adds `--runs`, `--host`, `--port`, `--reproducers-dir`, and `--cli-server-hold`. `gen_payload` adds `--format` (`php-serialize` or `json`), `--workers`, and `--out`.

## Usage Examples

### Multi-run fuzzing via fork mode

```bash
./fuzzer \
  --php="$(command -v php)" \
  --php-ini=/path/to/relay.ini \
  --ops=1000 \
  --runs=50 \
  --workers=8 \
  --mode=fork \
  --commands=string,hash,zset
```

This spins up 8 forked Relay clients per run, each performing 1,000 operations that touch only the `string`, `hash`, and `zset` families. Output for each run lands in `.fuzzer/runs/run-00000`, `.fuzzer/runs/run-00001`, etc.

### Using the CLI server mode

```bash
./fuzzer \
  --php="$(command -v php)" \
  --php-ini=/path/to/relay.ini \
  --ops=400 \
  --runs=10 \
  --workers=4 \
  --mode=cli-server \
  --host=127.0.0.1 \
  --port=8080
```

The fuzzer boots `php -S 127.0.0.1:8080 -t public` with `PHP_CLI_SERVER_WORKERS=4` and POSTs each worker payload to `public/fuzz.php`. This mode is handy when you want to exercise the extension inside the CLI server SAPI instead of the pcntl-enabled CLI.

### Re-running a single payload with `runner`

```bash
./runner \
  --php="$(command -v php)" \
  --php-ini=/path/to/relay.ini \
  --ops=1000 \
  --workers=8 \
  --seed=123456 \
  --mode=fork \
  --artifact-dir=/tmp/relay-run
```

Point `--artifact-dir` at an empty directory (or use one produced by the fuzzer) to keep payloads and summaries together. This is the quickest way to replay the seed that was captured in `reproducers/<timestamp>/seed.txt`.

### Generating a payload file

```bash
./gen_payload \
  --ops=250 \
  --seed=99 \
  --workers=2 \
  --commands=string,set \
  --format=json \
  --out=/tmp/payload.json
```

Inspect `/tmp/payload.json` to see the exact operations (`cmd` + `args`) that would be executed.

## Artifacts & Reproducers

- `.fuzzer/runs/run-xxxxx/` holds `stdout.txt`, `stderr.txt`, `summary.json`, and the serialized payload for every run.
- Failures (non-zero exit, worker failure, or detected core dump) create `reproducers/<timestamp>-<run>` containing:
  - `cmd.txt`, the exact command line used to invoke `runner`
  - `payload.json` / `payload.bin`
  - Logs and summaries
  - Any copied core files
- To replay a reproducer, read `cmd.txt` (or `seed.txt`) and pass the captured seed/options back to either `runner` or `fuzzer --runs=1`.

## Command Families

You can constrain the workload via `--commands`. Families currently exposed by `CommandRegistry` are:

| Family | Commands |
|--------|----------|
| `string` | `set`, `get`, `mget`, `del`, `incr`, `decr`, `append` |
| `hash` | `hset`, `hget`, `hmget`, `hdel`, `hincrby` |
| `list` | `lpush`, `rpush`, `lpop`, `rpop`, `lrange`, `llen` |
| `set` | `sadd`, `srem`, `sismember`, `smembers`, `scard` |
| `zset` | `zadd`, `zrem`, `zrange`, `zcard`, `zscore` |

The filter accepts either a family (e.g. `--commands=list`) or an individual command (e.g. `--commands=set,zadd`). When omitted the generator pulls from every command defined in `src/Payload/CommandRegistry.php`.

## Notes

- `runner` writes payload copies (`payload.bin` & `payload.json`) so you can feed them to external tooling or additional fuzzers.
- CLI-server mode expects to talk to `public/fuzz.php`. Customize that script if you need to bootstrap a different app or configure Relay differently.
- `RelayFactory` currently instantiates `new Relay\Relay()` with default parameters. Update `src/Runner/RelayFactory.php` if your environment needs custom DSNs or authentication.

Happy fuzzing!

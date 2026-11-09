# ptouch simulator

`ptouch` in this folder behaves like the real [ptouch](https://github.com/khang-11/ptouch) command
line tool with a P-Touch printer attached. It is used by the PHP and browser tests, the demo, and
the screenshots, so no printer is needed.

```sh
PTSIM_OUT=/tmp/labels tools/ptouch-sim/ptouch --status --json
LABELER_PTOUCH_BIN=$PWD/tools/ptouch-sim/ptouch php -S 127.0.0.1:8080 -t public
```

State comes from the `PTSIM_*` environment variables (or a JSON file named by `PTSIM_STATE`):

| Variable | Default | Purpose |
| --- | --- | --- |
| `PTSIM_PRINTER` | `PT-P700` | model, or `none` for no printer (`PT-P900W` is 360 dpi) |
| `PTSIM_SERIAL` | `000F1Z401234` | USB serial number |
| `PTSIM_MODE` | `normal` | `plite`, `unsupported` or `access` for the matching problems |
| `PTSIM_TAPE_MM` | `12` | tape width, `0` for no cassette |
| `PTSIM_MEDIA_TYPE` | `1` | media type code (1 laminated, 0x11/0x17 heat-shrink) |
| `PTSIM_TAPE_COLOR` | `1` | tape colour code |
| `PTSIM_TEXT_COLOR` | `8` | text colour code |
| `PTSIM_ERROR` | `0` | status error bits, e.g. `4096` (0x1000) cover open, `4` cutter jam |
| `PTSIM_OUT` | empty | folder for printed labels; empty discards them |
| `PTSIM_DELAY_MS` | `0` | how long a print takes |
| `PTSIM_OTHERS` | `[]` | more printers as JSON, e.g. `[{"printer":"PT-P750W","serial":"B2","tape_mm":24}]` |
| `PTSIM_FAIL` | empty | `print` for a write error, `jam` for a print that succeeds and then reports a jam |
| `PTSIM_VERSION` | `1.9` | version reported by `--version` |
| `PTSIM_STATE` | empty | JSON file whose values override the environment |

Each printed label is stored as `label-NNNN.png` and `label-NNNN.json` in `PTSIM_OUT`.

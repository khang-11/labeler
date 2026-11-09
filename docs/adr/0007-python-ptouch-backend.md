# 0007. The ptouch Python backend replaces ptouch-print

Date: 2026-11-09 · Status: accepted

## Context

Labeler talked to the printer through ptouch-print, a C program that only speaks USB and whose
status output is human-readable text. That tied Labeler to one program, made network printers
impossible, and forced a large simulator and a contract test that built four ptouch-print versions.
The status text also had to be parsed version by version.

The fork `khang-11/ptouch` is a Python library that supports USB and network printers. It returns
the printer's 32-byte status block as JSON (`--status --json`), including model, serial, DPI,
media type/width, tape pixel height and error bits. On the network the same block is read over
SNMP, because Brother's raw print port is one-way. It can also detect the printer model and the
loaded tape, and discover printers on the network or USB.

## Decision

Labeler calls the `ptouch` command line instead of ptouch-print:

* `ptouch --status --json` for status, mapped into the same `printer` array the API and browser
  already understood.
* `ptouch --image label.png ...` for printing, with `--no-margin`, `--full-cut`, `--chain` and
  `--precut` to match the old output.
* A `printers` map in `config.php` (or `LABELER_PRINTERS` JSON) lists network printers by host and
  USB printers by serial. Model and tape are detected; `model` and `tape_width` are overrides.

ptouch is installed into a virtual environment with Python 3.11+, Pillow and packbits.

## Consequences

* Labeler now needs Python 3.11+ (Bookworm / Debian 12 or newer) in addition to PHP. The Pi Zero
  installation gets bigger; a network printer needs no USB or printer agent.
* The browser and API status shape stays the same, so the renderer and most of the UI do not
  change.
* The ptouch simulator replaces the ptouch-print simulator. The contract test against four
  ptouch-print versions is removed; the JSON status contract is covered by the simulator and the
  PHP tests.
* `--no-margin` must be verified against a real printer: the PNG already contains the label's
  margins, so ptouch must not add another feed margin.

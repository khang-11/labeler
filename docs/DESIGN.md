# Design

Status date: 2026-11-09

## Goal

Print labels on a P-Touch printer from a phone or computer in the house, with a preview that is
exactly the printout, without installing anything on the client and with as little as possible on
the small computer the printer hangs on.

## How it works

```
 Browser                                            Raspberry Pi
 ───────────────────────────────────────────        ──────────────────────────────────────────
 editor (main.js) ── label model (label.js)          public/api.php ── src/Api.php
        │                                                │  status:  ptouch --version
        ▼                                                │           ptouch --status --json
 render.js: one canvas per block at the tape's           │  print:   checks the PNG (Png.php),
 pixel height and dpi ─▶ black/white bitmap              │           writes a temporary file,
 (bitmap.js; qr.js, code128.js draw directly)            │           ptouch [--copies n]
        │                                                │           [--full-cut] [--chain]
        ▼                                                │           [--precut] [--no-margin]
 png.js: 1-bit palette PNG ── POST JSON ───────────────▶ │           --image label.png
                                                         │  (proc_open with an argument list,
                                                         │   no shell; output in the print log)
 preview canvas (tape colour from status) ◀── status ──── │
```

Icons are searched and downloaded by the browser from an Iconify API (`icon_api`); the SVG of
the chosen icon is stored in the label and rendered like an image, so neither the Pi nor printing
depend on that service.

The browser does all the drawing (ADR [0001](adr/0001-render-in-the-browser.md)). The server knows
nothing about fonts, QR codes or layout; it answers four questions: what is attached (`status`),
print this image (`print`), which fonts are in the folder (`fonts`, `font`), and what happened
(`log`).

Split setup ([ADR 0006](adr/0006-printer-agent.md)): the interface can forward `status`, `print`
and `log` to a printer agent elsewhere (`printer_url`), which is the same API without the
interface, protected by an access token. The browser still renders; the interface checks the
request; the agent checks the PNG against its tape and prints.

## ptouch backend

ptouch is the Python library in `~/ptouch` (a fork of nbuchwitz/ptouch). Labeler calls its
command line:

* Status: `ptouch --status --json`. Over USB the raster status request is answered by the
  printer; over the network the raw print port (TCP 9100) is one-way, so ptouch reads the same
  32-byte status block over SNMP. The model and the loaded tape are part of that status, so
  Labeler does not need to be told the model or the tape width.
* Print: `ptouch --image label.png [--host H | --usb [--serial S]] [--copies n] [--full-cut]
  [--chain] [--precut] [--no-margin]`. `--no-margin` matches ptouch-print's image mode: the PNG
  already contains the label's margins.
* Printers: a `printers` map in `config.php` (or `LABELER_PRINTERS` JSON) lists network printers
  by host and USB printers by serial. Model and tape are detected; `model`/`tape_width` are
  overrides for unusual media.
* Discovery: `ptouch --scan 192.168.8.0/24` finds Brother printers with an SNMP sweep;
  `ptouch --list-connected` lists USB printers. A discovery button in the UI is a follow-up.

The backend needs Python 3.11+ and Pillow, so the "no runtime dependencies" decision in
[ADR 0002](adr/0002-plain-php-no-build.md) is superseded in part by
[ADR 0007](adr/0007-python-ptouch-backend.md). PHP and the browser code are unchanged.

## Decisions

See the [architecture decision records](adr/README.md):

1. [0001](adr/0001-render-in-the-browser.md) Labels are rendered in the browser and printed as a 1-bit PNG.
2. [0002](adr/0002-plain-php-no-build.md) Plain PHP and ES modules, no framework, no build step, no runtime dependencies.
3. [0003](adr/0003-simulated-printer.md) Tests run against a simulated ptouch; the simulator mirrors the ptouch JSON status contract.
4. [0004](adr/0004-php-built-in-server.md) PHP's built-in server under systemd is the default deployment.
5. [0005](adr/0005-labels-in-the-browser.md) Saved labels live in the browser's storage.
6. [0006](adr/0006-printer-agent.md) A printer agent with an access token lets the interface run elsewhere.
7. [0007](adr/0007-python-ptouch-backend.md) The ptouch Python backend replaces ptouch-print.

## Limits and open questions

- Tested against the simulator and a real PT-P900W; other printers are the user's test.
- Text is drawn with the browser's fonts, so the same label can differ slightly between devices
  when a font is missing on one of them. Fonts in the server's `fonts/` folder avoid that.
- Anti-aliased text is cut at 50 % grey. Very small text (below about 6 pt at 180 dpi) is better
  set in bold.
- A P-Touch printer cannot be switched on over USB (no such command in Brother's raster command
  reference; USB remote wakeup only goes from the device to the host). Labeler explains a printer
  that switched itself off; INSTALL.md lists what helps.
- Several printers on one computer are listed with `printers` (or USB discovery); the browser picks one, the `printer` setting is the default. Printers on different computers each need a printer agent; one interface talks to one agent.

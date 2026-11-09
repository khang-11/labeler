# Labeler

Design and print labels on a Brother P-Touch printer from any browser on your network: text in
any font, thousands of icons, QR codes, barcodes and images, with a live preview that shows exactly what the printer
will print.

[![CI](https://github.com/PhilippMundhenk/labeler/actions/workflows/ci.yml/badge.svg)](https://github.com/PhilippMundhenk/labeler/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

![The editor with a label of a logo, two lines of text and a QR code](docs/screenshots/editor.png)

Labeler is a small web front end for [ptouch](https://github.com/khang-11/ptouch)
which does the actual talking to the printer over USB or the network. It is made for a
Raspberry Pi Zero next to the printer: a few PHP files and static pages, no database, no build
step. The label is drawn in your browser, so the Pi only passes it on. The interface can also run
in Docker on a server, with only a small printer agent next to the printer.

> Labeler is an independent open-source project. It has no relation to Brother Industries and is
> neither endorsed nor supported by them. "Brother" and "P-Touch" only say which printers it
> works with.

**Works with:** the P-Touch printers ptouch supports: PT-E550W, PT-P710BT, PT-P750W, PT-P900,
PT-P900W, PT-P910BT and PT-P950NW. Tape from 3.5 to 36 mm, heat-shrink tube, 180 and 360 dpi.
Needs Python 3.11 or newer for ptouch.

## Features

- **Live preview** at the printer's resolution, in the colours of the inserted tape.
- **Blocks** side by side on one label: text, icon, QR code, Code 128 barcode, image, space.
  Drag them into order, duplicate and remove them; drop image files onto the page to add them.
- **Icons** with search: about 7,400 [Material Design Icons](https://pictogrammers.com/library/mdi/)
  and 3,500 brand logos from [Simple Icons](https://simpleicons.org/), loaded on demand from the
  [Iconify API](https://iconify.design/); the chosen icon is stored in the label.
- **Text**: one to several lines, any font (built-in list, fonts you put on the Pi, or the fonts of
  your computer in Chrome and Edge), automatic or fixed size, a size per line, bold, italic,
  underline, letter spacing, alignment, white on black.
- **QR codes** for links, text, Wi-Fi access, contacts, e-mail and phone numbers, with selectable error correction, **barcodes** (Code 128, EAN-13, UPC-A, Code 39) with or without text below.
- **Placeholders**: {date}, {time} and a counter {n} that gives every copy its own number.
- **Batch printing**: paste a list or load a CSV file and print one label per row, with {1} or
  {column name} in any block.
- **Images** (PNG, JPEG, SVG): black and white or dithered, adjustable threshold, invert.
- **Rotation** of text, barcodes, images and icons by 90, 180 or 270 degrees, e.g. for folder
  spines and shelf edges.
- **Layout**: margins, gap, minimum length with position, frame, cut marks, cable flags (content on both sides of a cable), mirrored for clear tape.
- **Printing**: copies, chain printing and auto cut where the installed ptouch and the
  printer support them; download the label as PNG.
- **Several printers** on one computer or network: configure them in `printers` and pick one in the printer details.
- **Printer discovery**: scan the network for Brother printers (SNMP) and pick one in the printer details; USB printers are listed automatically.
- **Printer status** with tape width and colour, clear messages for a missing printer, missing
  tape, open cover, cutter jam, overheating, weak batteries, P-Lite mode or missing USB permissions,
  a check after every print job (ptouch itself does not notice a jam during printing), a hint
  when the printer switched itself off, and a log of the last print jobs.
- **Templates** to start from: asset tag with numbered QR code, Wi-Fi guest access, cable label,
  folder spine, address, name tag, shelf label with barcode.
- **Undo and redo** for every change, also with Ctrl+Z and Ctrl+Shift+Z.
- **Recent prints** with a small picture of each label, to load or print again.
- **Saved labels** on the server, shared by phone and computer (or in the browser when the server
  cannot store them); the current label is kept between visits.
- English and German, optional password, light and dark mode, works on phones, can be added to the home screen.

## Try it in Docker

```sh
docker run --rm -p 8080:8080 -e LABELER_DEMO=1 ghcr.io/philippmundhenk/labeler
```

opens a demo with a simulated printer at <http://localhost:8080/>. For real use, run the interface
in Docker on your server with [`docker-compose.yml`](docker-compose.yml) and set
`LABELER_PRINTER_HOST` to the printer's address; model and tape are detected. A printer on another
computer can also be reached through the printer agent (below), and a printer plugged into the
Docker host can be passed through the USB bus. Details: [docs/DOCKER.md](docs/DOCKER.md).

## Quick start on a Raspberry Pi

The install script sets up everything below in one go (packages, ptouch, Labeler, printer
access, service) and updates an existing installation when run again:

```sh
curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh -o install.sh
less install.sh       # read what it does
sudo sh install.sh    # options: sh install.sh --help
```

Or by hand:

1. Install PHP (`sudo apt install php-cli`) and ptouch in a virtual environment (Python 3.11+):
   [docs/INSTALL.md](docs/INSTALL.md#1-ptouch).
2. Download `labeler-<version>.zip` from the [releases page](https://github.com/PhilippMundhenk/labeler/releases)
   and unpack it to `/opt/labeler`.
3. Allow the service to use the printer and start it:

   ```sh
   sudo useradd --system --groups lp labeler
   sudo cp /opt/labeler/deploy/99-labeler-ptouch.rules /etc/udev/rules.d/
   sudo udevadm control --reload-rules        # then unplug and plug in the printer
   sudo cp /opt/labeler/deploy/labeler.service /etc/systemd/system/
   sudo systemctl enable --now labeler
   ```

4. Open `http://<raspberry-pi>:8080/`. The badge in the top right corner shows the printer and the
   tape; click it for details and the print log.

### Printer on another computer

The printer can hang on a small computer anywhere in the house while the interface runs on a
server, for example in Docker. On the computer with the printer, install only the printer agent
(the API, protected by an access token it generates):

```sh
sudo sh install.sh --agent
```

It prints the two settings for the interface: `LABELER_PRINTER_URL=http://<pi>:8631` and
`LABELER_PRINTER_TOKEN=...`. Status, printing and the print log then go through the agent.

Already running a web server with PHP? Point it at `/opt/labeler/public` instead of step 3's
service; [docs/INSTALL.md](docs/INSTALL.md) has nginx and lighttpd examples, updates and
troubleshooting.

## Configuration

Nothing needs to be configured. To change a setting, copy `config.example.php` to `config.php`, or
set the environment variable (in `labeler.service`: `Environment=`).

| Setting | Variable | Default | Purpose |
| --- | --- | --- | --- |
| `ptouch_bin` | `LABELER_PTOUCH_BIN` | `ptouch` | ptouch program, name or full path |
| `printers` | `LABELER_PRINTERS` | first USB printer | JSON map of printer id to `{host}` or `{usb: true, serial}` |
| `printer` | `LABELER_PRINTER` | first printer | Default printer id |
| `printer_host` | `LABELER_PRINTER_HOST` | empty | Shorthand for one network printer |
| `printer_model` | `LABELER_PRINTER_MODEL` | empty | Optional model override (network/USB) |
| `printer_tape_width` | `LABELER_PRINTER_TAPE_WIDTH` | empty | Optional tape width override in mm |
| `printer_community` | `LABELER_PRINTER_COMMUNITY` | `public` | SNMP read community |
| `vertical_offset` | `LABELER_VERTICAL_OFFSET` | automatic | Pixels to shift the print area down; empty lets ptouch centre it |
| `discovery_cidr` | `LABELER_DISCOVERY_CIDR` | empty | Network for discovery (default: local /24) |
| `timeout` | `LABELER_TIMEOUT` | `60` | Seconds a print job may take |
| `serial` | `LABELER_SERIAL` | empty | Legacy shorthand for the default USB printer serial |
| `data_dir` | `LABELER_DATA_DIR` | `./data` | Writable folder for the print log; empty switches the log off |
| `fonts_dir` | `LABELER_FONTS_DIR` | `./fonts` | `.ttf`, `.otf`, `.woff`, `.woff2` files for the font list |
| `max_upload_bytes` | `LABELER_MAX_UPLOAD_BYTES` | `2000000` | Largest label image |
| `max_copies` | `LABELER_MAX_COPIES` | `50` | Most copies per print |
| `log_entries` | `LABELER_LOG_ENTRIES` | `50` | Entries kept in the print log |
| `token` | `LABELER_TOKEN` | empty | Access token for the API (`Authorization: Bearer`); set by `install.sh --agent` |
| `printer_url` | `LABELER_PRINTER_URL` | empty | Printer agent to print with, e.g. `http://printer-pi:8631`; empty prints on this computer |
| `printer_token` | `LABELER_PRINTER_TOKEN` | empty | Access token of that agent |
| `icon_api` | `LABELER_ICON_API` | `https://api.iconify.design` | Iconify API the browser searches icons with; your own Iconify server, or empty to switch icons off |
| `password` | `LABELER_PASSWORD` | empty | Password for the interface, plain or a `password_hash()` hash; empty: no login |

Without a password, anyone who can open the page can print. On a home network that is usually
fine; otherwise set `password`. See [SECURITY.md](SECURITY.md).

## Screenshots

| Phone | No printer attached | Printer details and print log |
| --- | --- | --- |
| ![Phone](docs/screenshots/phone.png) | ![No printer](docs/screenshots/no-printer.png) | ![Details](docs/screenshots/details.png) |

Dark mode and more: [docs/screenshots.md](docs/screenshots.md).

## Documentation

- [docs/INSTALL.md](docs/INSTALL.md): installing ptouch and Labeler, the printer agent, web
  servers, updates, troubleshooting.
- [docs/DOCKER.md](docs/DOCKER.md): the container image, demo mode, printer agent or USB.
- [docs/DESIGN.md](docs/DESIGN.md): how it works, the ptouch backend, and the
  [architecture decision records](docs/adr/README.md).
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md): tests (simulated printer, browser tests, contract
  test against the real ptouch), coverage, releases.
- [docs/BRANDING.md](docs/BRANDING.md): name, colours and components.
- [CHANGELOG.md](CHANGELOG.md): what changed in each release.

Questions and problems: [GitHub issues](https://github.com/PhilippMundhenk/labeler/issues).

## License

[MIT](LICENSE). The bundled QR code generator (`public/lib/qrcode.mjs`) is by Kazuhiko Arase,
also MIT.

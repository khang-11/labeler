# Installation

Labeler runs where the printer is plugged in, typically a Raspberry Pi (a Zero W is enough) with
Raspberry Pi OS. It needs PHP 7.4 or newer and [ptouch](https://github.com/khang-11/ptouch), which needs Python 3.11 or newer. The steps below assume
Raspberry Pi OS or Debian; other Linux distributions work the same way with their package names.

## Install script

[`deploy/install.sh`](../deploy/install.sh) does steps 1 to 4a below: installs the packages, installs
ptouch, downloads the newest release to `/opt/labeler`, creates the user `labeler`, installs
the udev rule and the systemd service. Run it again to update; `config.php` and the fonts are
kept, the previous version stays in `/opt/labeler.old`.

```sh
curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh -o install.sh
sudo sh install.sh                       # newest release, port 8080
sudo sh install.sh --port 80 --skip-ptouch
sudo sh install.sh --zip labeler-1.0.0.zip --version 1.0.0
sh install.sh --help
```

Installing ptouch needs Python 3.11 or newer (Raspberry Pi OS Bookworm / Debian 12 or newer). CI runs the script on Debian bookworm and trixie for every change.

## Printer agent: printer and interface on different computers

The interface can run on a server (for example in Docker) while the printer hangs on a small
computer elsewhere. That computer only runs the printer agent: Labeler's API without the
interface, protected by an access token.

```sh
sudo sh install.sh --agent
```

installs ptouch, the agent in `/opt/labeler-agent`, the udev rule and the service
`labeler-agent` on port 8631, generates a token (kept in `/opt/labeler-agent/config.php`, readable
only by root and the service) and prints:

```
LABELER_PRINTER_URL=http://192.0.2.10:8631
LABELER_PRINTER_TOKEN=3f9c...
```

Set both on the interface (`config.php` keys `printer_url` and `printer_token`, or environment
variables). The printer badge and the details dialog then show the agent; when it cannot be
reached or the token is wrong, they say so. Running the script again with `--agent` updates the
agent and keeps the token. Without `--no-service`, `systemctl status labeler-agent` shows it.

The token travels in plain HTTP. On your own network that is usually fine; across networks you
do not trust, put the agent behind a reverse proxy with TLS or a VPN.

## 1. ptouch

Debian and Raspberry Pi OS do not package ptouch, so install it from the fork into a virtual
environment:

```sh
sudo apt install python3 python3-venv python3-pil python3-setuptools git
sudo git clone https://github.com/khang-11/ptouch.git /opt/ptouch-src
sudo python3 -m venv --system-site-packages /opt/ptouch
sudo /opt/ptouch/bin/pip install --no-deps /opt/ptouch-src
sudo /opt/ptouch/bin/pip install packbits
sudo ln -s /opt/ptouch/bin/ptouch /usr/local/bin/ptouch
ptouch --version
```

Python 3.11 or newer is required (Bookworm / Debian 12 or newer). With the printer connected and
switched on (mode switch on **E**, not EL), check it:

```sh
ptouch --status --usb
```

`Media width: 12 mm` and `Device status: READY` mean the printer and the tape are found. For a
network printer use `ptouch --status --host <printer-ip>`; no model or tape setting is needed.

## 2. Labeler

```sh
sudo apt install php-cli unzip
cd /opt
sudo unzip ~/labeler-1.0.0.zip   # from the releases page
```

The release contains `public/` (the web root), `src/` (the PHP code, outside the web root),
`deploy/` (examples), `fonts/` and `config.example.php`.

## 3. Printer access for the service

ptouch's own udev rule only lets users who are logged in at the console use the printer.
A service needs a group:

```sh
sudo useradd --system --groups lp labeler
sudo cp /opt/labeler/deploy/99-labeler-ptouch.rules /etc/udev/rules.d/
sudo udevadm control --reload-rules
```

Unplug the printer and plug it in again. The rule gives the group `lp` access to Brother USB
devices.

## 4a. Run it with PHP's built-in server (recommended)

No Apache or nginx needed:

```sh
sudo cp /opt/labeler/deploy/labeler.service /etc/systemd/system/
sudo systemctl enable --now labeler
```

Open `http://<raspberry-pi>:8080/`. The port is set in `ExecStart` of the unit file. The print log
is kept in `/var/lib/labeler`. Check the service with `systemctl status labeler` and
`journalctl -u labeler`.

## 4b. Or use your web server

Make `/opt/labeler/public` the document root (or an alias) and make sure the PHP process user is in
the group `lp`:

- nginx with PHP-FPM: [`deploy/nginx.conf`](../deploy/nginx.conf), then
  `sudo usermod -aG lp www-data` and restart PHP-FPM.
- lighttpd: [`deploy/lighttpd.conf`](../deploy/lighttpd.conf) serves Labeler under `/labeler/`,
  then `sudo usermod -aG lp www-data` and restart lighttpd.
- Apache: `Alias /labeler /opt/labeler/public` with `mod_php` or PHP-FPM.

With your own web server, let it handle caching and compression (nginx: `gzip on;`, `etag on;`);
with the built-in server, `src/router.php` does it (the service file already uses it).

For the print log, create a writable data folder: `sudo install -d -o www-data /opt/labeler/data`.
Never make the whole `/opt/labeler` the document root: `src/` and `config.php` must not be served.

## Configuration

Optional. Copy `config.example.php` to `/opt/labeler/config.php` and change what you need, or set
`LABELER_*` variables (`Environment=` lines in `labeler.service`). All settings are listed in the
[README](../README.md#configuration). Fonts: copy `.ttf`, `.otf`, `.woff` or `.woff2` files into
`/opt/labeler/fonts`.

## Updating

```sh
cd /opt
sudo mv labeler labeler.old
sudo unzip ~/labeler-1.1.0.zip
sudo cp labeler.old/config.php labeler/ 2>/dev/null; sudo cp -r labeler.old/fonts/. labeler/fonts/
sudo systemctl restart labeler
```

Coming from the first version of Labeler (a single `index.php` in the web root): remove
`index.php`, `main.css` and `preview.png` from the old web root and install as above.

## Troubleshooting

The badge in the top right corner shows the state; click it for the details and the print log
with the exact ptouch command and output.

| Message | What to do |
| --- | --- |
| ptouch was not found | Install it (step 1) or set `ptouch_bin` to its full path, e.g. `/usr/local/bin/ptouch`. |
| No printer found | USB cable, power, mode switch on **E**. `lsusb` should list a Brother device. |
| A printer is attached, but the web server may not use it | udev rule and group (step 3); unplug and plug in the printer; restart the service. |
| The printer is in P-Lite mode | Mode switch from EL to E, or hold the PLite button for two seconds. |
| No tape cassette inserted | Insert tape; close the cover. |
| The cover is open / Cutter jam / Replace the tape cassette / overheated / batteries weak | What the printer reports about itself; the message says what to do. Unknown error bits are shown as `error 0x…`. |
| Chain printing / auto cut is greyed out | The installed ptouch is too old: chain needs 1.6, auto cut 1.8. Auto cut also needs a printer with a cutter. |

### The printer switches itself off

P-Touch printers switch themselves off after a while without use (auto power off). Switched off,
they disappear from USB, so Labeler shows "No printer" and, if it saw the printer a moment
before, says that it probably switched itself off. **A P-Touch printer cannot be switched on over
USB**: Brother's raster command reference has no power command, and a computer can only wake a
sleeping USB device, not one that is off. What helps:

- Turn auto power off off: Brother's *Printer Setting Tool* (Windows, macOS), *Device Settings*,
  *Auto Power Off* = *None* (separately for AC adapter and batteries). The printer keeps the
  setting, so this is needed once.
- Some models (PT-P900 series) have *Auto Power On*: they switch on when power is connected, so a
  smart plug can switch them on and off.
- Switching the USB port off and on (`uhubctl`) does not help: the printer has its own power supply.

Run ptouch as the service user to see what the service sees:

```sh
sudo -u labeler ptouch --info
```

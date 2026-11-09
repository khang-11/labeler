<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Wrapper around the ptouch command line tool.
 *
 * ptouch is a Python program (https://github.com/khang-11/ptouch) that talks to
 * Brother P-touch printers over USB or the network. It returns status as JSON,
 * so Labeler no longer has to parse the human-readable output of ptouch-print.
 */
final class Ptouch
{
    /** Feature => supported. ptouch supports all of them. */
    public const FEATURES = [
        'copies' => true,
        'chain' => true,
        'precut' => true,
        'serial' => true,
        'network' => true,
    ];

    private const TAPE_COLORS = [
        0x01 => 'White', 0x02 => 'Other', 0x03 => 'Clear', 0x04 => 'Red', 0x05 => 'Blue',
        0x06 => 'Yellow', 0x07 => 'Green', 0x08 => 'Black', 0x09 => 'Clear', 0x20 => 'Matte White',
        0x21 => 'Matte Clear', 0x22 => 'Matte Silver', 0x23 => 'Satin Gold', 0x24 => 'Satin Silver',
        0x30 => 'Blue', 0x31 => 'Red', 0x40 => 'Fluorescent Orange', 0x41 => 'Fluorescent Yellow',
        0x50 => 'Berry Pink', 0x51 => 'Light Gray', 0x52 => 'Lime Green', 0x60 => 'Yellow',
        0x61 => 'Pink', 0x62 => 'Blue', 0x70 => 'Heat-shrink Tube', 0x71 => 'Heat-shrink Tube white',
        0x90 => 'White', 0x91 => 'Yellow', 0xf0 => 'Cleaning', 0xf1 => 'Stencil', 0xff => 'Incompatible',
    ];

    private const TEXT_COLORS = [
        0x01 => 'White', 0x02 => 'Other', 0x04 => 'Red', 0x05 => 'Blue', 0x08 => 'Black',
        0x0a => 'Gold', 0x62 => 'Blue', 0xf0 => 'Cleaning', 0xf1 => 'Stencil', 0xff => 'Incompatible',
    ];

    /** Error bits as Brother's status block reports them: low byte error info 1, high byte error info 2. */
    public const ERRORS = [
        0x0001 => 'No tape: insert a tape cassette.',
        0x0004 => 'Cutter jam: open the cover and remove the jammed tape.',
        0x0008 => 'The batteries are weak: connect the AC adapter.',
        0x0040 => 'Wrong AC adapter: the voltage is too high.',
        0x0100 => 'Replace the tape cassette: it is empty or does not fit.',
        0x1000 => 'The cover is open: close it.',
        0x2000 => 'The printer is overheated: let it cool down.',
    ];

    private string $bin;
    private int $timeout;
    /** @var array<string, array<string, mixed>> */
    private array $printers;
    private string $current;
    private ?Log $log;
    private ?string $version = null;

    /**
     * @param array<string, array<string, mixed>> $printers
     */
    public function __construct(
        string $bin,
        array $printers,
        string $defaultPrinter,
        int $timeout = 60,
        ?Log $log = null
    ) {
        $this->bin = $bin;
        $this->printers = $printers;
        $this->current = isset($printers[$defaultPrinter]) ? $defaultPrinter : (string) (array_key_first($printers) ?? '');
        $this->timeout = $timeout;
        $this->log = $log;
    }

    public static function fromConfig(Config $config, ?Log $log = null): self
    {
        return new self(
            (string) $config->get('ptouch_bin'),
            (array) $config->get('printers'),
            (string) $config->get('printer'),
            (int) $config->get('timeout'),
            $log
        );
    }

    /** Installed version as "major.minor", or null when ptouch cannot be run. */
    public function version(): ?string
    {
        if ($this->version === null) {
            $result = $this->run(['--version'], false);
            $this->version = '';
            if ($result['code'] !== 127 && preg_match('/(\d+)\.(\d+)/', $result['output'], $m)) {
                $this->version = $m[1] . '.' . $m[2];
            }
        }
        return $this->version === '' ? null : $this->version;
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        return self::FEATURES;
    }

    /** Printer id currently selected for requests. */
    public function currentId(): string
    {
        return $this->current;
    }

    /** The same tool, talking to the printer with this id (empty: keep the current one). */
    public function withPrinter(string $id): self
    {
        if ($id === '' || $id === $this->current) {
            return $this;
        }
        if (strncmp($id, 'usb:', 4) === 0) {
            $id = substr($id, 4);
        }
        if (isset($this->printers[$id])) {
            $copy = clone $this;
            $copy->current = $id;
            return $copy;
        }
        foreach ($this->printers as $printerId => $printer) {
            if (($printer['serial'] ?? '') === $id) {
                $copy = clone $this;
                $copy->current = $printerId;
                return $copy;
            }
        }
        // Select an arbitrary USB printer by serial (the list from --list-connected).
        $copy = clone $this;
        $copy->printers['__selected'] = [
            'id' => '__selected', 'host' => '', 'usb' => true, 'serial' => $id,
            'model' => '', 'community' => 'public', 'tape_width' => 0.0,
        ];
        $copy->current = '__selected';
        return $copy;
    }

    /**
     * Printer and tape status from `ptouch --status --json`.
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $printer = $this->printerConfig();
        if ($printer === null) {
            return self::emptyStatus();
        }
        $result = $this->run($this->statusArgs($printer), false);
        $status = json_decode($result['output'], true);
        if (!is_array($status)) {
            return self::parseError($result['code'], $result['output'], $printer);
        }
        return self::parseStatus($status, $result['code'], $result['output'], $printer);
    }

    /**
     * Configured printers, or USB printers when no printer is configured.
     *
     * @return list<array{id: string, model: string|null, serial: string|null, usable: bool, kind: string, host: string|null}>
     */
    public function printers(): array
    {
        $usbOnly = true;
        foreach ($this->printers as $printer) {
            if (!$printer['usb']) {
                $usbOnly = false;
                break;
            }
        }
        if ($usbOnly) {
            $usb = $this->usbPrinters();
            if ($usb !== []) {
                return $usb;
            }
        }
        $printers = [];
        foreach ($this->printers as $id => $printer) {
            $copy = $this->withPrinter($id);
            $info = $copy->info();
            $printers[] = [
                'id' => $id,
                'model' => $info['model'] ?? ($printer['model'] !== '' ? $printer['model'] : null),
                'serial' => $info['serial'] ?? ($printer['serial'] !== '' ? $printer['serial'] : $id),
                'usable' => (bool) $info['connected'],
                'kind' => $printer['host'] !== '' ? 'network' : 'usb',
                'host' => $printer['host'] !== '' ? $printer['host'] : null,
            ];
        }
        return $printers;
    }

    /**
     * Print a 2-colour palette PNG.
     *
     * @return array{ok: bool, output: string, notes: list<string>}
     */
    public function printImage(string $png, int $copies = 1, bool $chain = false, bool $precut = false): array
    {
        $printer = $this->printerConfig();
        if ($printer === null) {
            return ['ok' => false, 'output' => 'No printer configured.', 'notes' => []];
        }
        $args = $this->connectionArgs($printer);
        if ($printer['model'] !== '') {
            $args[] = '--printer';
            $args[] = $printer['model'];
        }
        if ($printer['tape_width'] > 0) {
            $args[] = '--tape-width';
            $args[] = self::number($printer['tape_width']);
        }
        if ($copies > 1) {
            $args[] = '--copies';
            $args[] = (string) $copies;
            $args[] = '--full-cut';
        }
        if ($chain) {
            $args[] = '--chain';
        }
        if ($precut) {
            $args[] = '--precut';
        }
        // The browser already draws the label's margins into the PNG.
        $args[] = '--no-margin';
        $args[] = '--image';
        $args[] = $png;

        $result = $this->run($args, true);
        $output = trim($result['output']);
        $failed = $result['code'] !== 0
            || preg_match('/error:|failed|not ready|no supported|could not|too large|nothing to print/i', $output);
        return ['ok' => !$failed, 'output' => $output, 'notes' => []];
    }

    /** @return array<string, mixed>|null */
    private function printerConfig(): ?array
    {
        return $this->printers[$this->current] ?? null;
    }

    /**
     * @param array<string, mixed> $printer
     * @return list<string>
     */
    private function connectionArgs(array $printer): array
    {
        if ($printer['host'] !== '') {
            $args = ['--host', (string) $printer['host']];
            if ($printer['community'] !== '') {
                $args[] = '--snmp-community';
                $args[] = (string) $printer['community'];
            }
            return $args;
        }
        $args = ['--usb'];
        if ($printer['serial'] !== '') {
            $args[] = '--serial';
            $args[] = (string) $printer['serial'];
        }
        return $args;
    }

    /**
     * @param array<string, mixed> $printer
     * @return list<string>
     */
    private function statusArgs(array $printer): array
    {
        return array_merge($this->connectionArgs($printer), ['--status', '--json']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $printer
     * @return array<string, mixed>
     */
    private static function parseStatus(array $data, int $code, string $output, array $printer): array
    {
        $connected = !empty($data['connected']);
        $errorCode = isset($data['error_code']) && is_int($data['error_code']) ? $data['error_code'] : 0;
        $errors = self::decodeErrors($errorCode);
        $width = isset($data['media_width_mm']) && is_numeric($data['media_width_mm'])
            ? (int) round((float) $data['media_width_mm'])
            : null;
        $model = isset($data['model']) && is_string($data['model']) && $data['model'] !== ''
            ? $data['model']
            : ($printer['model'] !== '' ? $printer['model'] : null);
        $message = isset($data['message']) && is_string($data['message']) ? trim($data['message']) : '';
        if (!$connected) {
            if ($code === 127) {
                $message = 'ptouch was not found. Install it or set ptouch_bin in config.php.';
            } elseif ($message === '' && trim($output) !== '') {
                $message = trim((string) preg_replace('/^Error:\s*/i', '', strtok($output, "\n") ?: $output));
            } elseif ($message === '') {
                $message = 'No printer found. Check the connection and that the printer is switched on.';
            }
        } elseif ($errors !== []) {
            $message = implode(' ', $errors);
        } elseif ($width === null || $width === 0) {
            $message = 'No tape cassette inserted.';
        } elseif (empty($data['ready']) && $message === '') {
            $message = 'The printer is not ready.';
        }

        return [
            'connected' => $connected,
            'ready' => !empty($data['ready']) && $connected,
            'model' => $model,
            'serial' => isset($data['serial']) && is_string($data['serial']) ? $data['serial'] : null,
            'dpi' => isset($data['dpi']) && is_numeric($data['dpi']) ? (int) $data['dpi'] : 180,
            'max_px' => isset($data['max_px']) && is_numeric($data['max_px']) ? (int) $data['max_px'] : null,
            'tape_px' => isset($data['tape_px']) && is_numeric($data['tape_px']) ? (int) $data['tape_px'] : null,
            'media_type' => isset($data['media_type']) && is_numeric($data['media_type']) ? (int) $data['media_type'] : null,
            'media_width_mm' => $width,
            'tape_color' => self::color(self::TAPE_COLORS, $data['tape_color'] ?? null),
            'text_color' => self::color(self::TEXT_COLORS, $data['text_color'] ?? null),
            'error_code' => $errorCode,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $printer
     * @return array<string, mixed>
     */
    private static function parseError(int $code, string $output, array $printer): array
    {
        $message = trim($output);
        if ($code === 127) {
            $message = 'ptouch was not found. Install it or set ptouch_bin in config.php.';
        } elseif ($message === '') {
            $message = 'ptouch did not return a status.';
        } else {
            $message = (string) preg_replace('/^Error:\s*/i', '', strtok($message, "\n") ?: $message);
        }
        $info = self::emptyStatus();
        $info['model'] = $printer['model'] !== '' ? $printer['model'] : null;
        $info['message'] = $message;
        return $info;
    }

    /** @return array<string, mixed> */
    public static function emptyStatus(): array
    {
        return [
            'connected' => false,
            'ready' => false,
            'model' => null,
            'serial' => null,
            'dpi' => 180,
            'max_px' => null,
            'tape_px' => null,
            'media_type' => null,
            'media_width_mm' => null,
            'tape_color' => null,
            'text_color' => null,
            'error_code' => null,
            'errors' => [],
            'message' => 'No printer configured. Set printers or printer_host in config.php.',
        ];
    }

    /**
     * @return list<array{id: string, model: string|null, serial: string|null, usable: bool, kind: string, host: string|null}>
     */
    private function usbPrinters(): array
    {
        $result = $this->run(['--list-connected', '--json'], false);
        $list = json_decode($result['output'], true);
        if (!is_array($list)) {
            return [];
        }
        $printers = [];
        foreach ($list as $index => $printer) {
            if (!is_array($printer)) {
                continue;
            }
            $serial = isset($printer['serial']) && is_string($printer['serial']) ? $printer['serial'] : '';
            $printers[] = [
                'id' => $serial !== '' ? 'usb:' . $serial : 'usb:' . $index,
                'model' => isset($printer['model']) && is_string($printer['model']) ? $printer['model'] : null,
                'serial' => $serial !== '' ? $serial : null,
                'usable' => !empty($printer['usable']),
                'kind' => 'usb',
                'host' => null,
            ];
        }
        return $printers;
    }

    /**
     * @param list<string> $args
     * @return array{code: int, output: string, timed_out: bool, ms: int}
     */
    private function run(array $args, bool $log): array
    {
        $result = Process::run(array_merge([$this->bin], $args), $this->timeout);
        if ($log && $this->log !== null) {
            $this->log->add($args, $result['code'], $result['output'], $result['ms']);
        }
        return $result;
    }

    /** @return list<string> one message per error bit, unknown bits named by their value */
    public static function decodeErrors(int $code): array
    {
        $messages = [];
        for ($bit = 1; $bit <= 0x8000; $bit <<= 1) {
            if ($code & $bit) {
                $messages[] = self::ERRORS[$bit] ?? sprintf('The printer reports error 0x%04x.', $bit);
            }
        }
        return $messages;
    }

    /**
     * @param array<int, string> $names
     * @param mixed $code
     * @return array{code: int, name: string}|null
     */
    private static function color(array $names, $code): ?array
    {
        if (!is_int($code) && !(is_string($code) && ctype_digit($code))) {
            return null;
        }
        $value = (int) $code;
        return ['code' => $value, 'name' => $names[$value] ?? 'Unknown'];
    }

    /** @param float|int $value */
    private static function number($value): string
    {
        return rtrim(rtrim(sprintf('%.1f', (float) $value), '0'), '.');
    }
}

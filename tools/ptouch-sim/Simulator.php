<?php

declare(strict_types=1);

namespace Labeler\Sim;

/**
 * A stand-in for the ptouch command line tool and a connected P-Touch printer.
 *
 * It accepts the arguments Labeler uses (--status --json, --image, --copies,
 * --chain, --precut, --list-connected --json) and returns the same JSON status
 * shape as ptouch. Printed labels are not sent anywhere: each one is stored as
 * PNG plus a JSON record in the output folder.
 *
 * State (see defaults()) comes from PTSIM_* environment variables and, when
 * PTSIM_STATE names a JSON file, from that file, so a running test can change it.
 */
final class Simulator
{
    /** tape mm => [px at 180 dpi, px at 360 dpi], from the ptouch pin configs */
    public const TAPES = [
        4 => [24, 48], 6 => [32, 64], 9 => [52, 106], 12 => [76, 150],
        18 => [120, 234], 21 => [124, 248], 24 => [128, 320], 36 => [192, 454],
    ];

    /** model => [max px, dpi] */
    public const PRINTERS = [
        'PT-P700' => [128, 180],
        'PT-P750W' => [128, 180],
        'PT-H500' => [128, 180],
        'PT-D600' => [128, 180],
        'PT-P300BT' => [64, 180],
        'PT-P900W' => [454, 360],
        'PT-P950NW' => [454, 360],
    ];

    private const MEDIA_TYPES = [
        0x00 => 'No media', 0x01 => 'Laminated tape', 0x03 => 'Non-laminated tape',
        0x04 => 'Fabric tape', 0x11 => 'Heat-shrink tube', 0x13 => 'Flexi tape',
        0x14 => 'Flexible ID tape', 0x15 => 'Satin tape', 0x17 => 'Heat-shrink tube',
        0xff => 'Incompatible tape',
    ];

    /** @var array<string, mixed> */
    private array $state;
    /** @var list<string> */
    private array $out = [];
    /** @var list<string> */
    private array $err = [];
    private string $stateFile;

    /** @param array<string, mixed> $state */
    public function __construct(array $state, string $stateFile = '')
    {
        $this->state = $state + self::defaults();
        $this->stateFile = $stateFile;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'version' => '1.9',      // ptouch version to imitate
            'printer' => 'PT-P700',  // model, or "none" for no printer
            'serial' => '000F1Z401234',
            'mode' => 'normal',      // normal | plite | unsupported | access
            'tape_mm' => 12,         // 0 = no cassette
            'media_type' => 1,       // 1 laminated
            'tape_color' => 1,       // 1 white
            'text_color' => 8,       // 8 black
            'error' => 0,            // status error bits, e.g. 0x1000 cover open, 0x0004 cutter jam
            'out' => '',             // folder for printed labels, empty = discard
            'delay_ms' => 0,         // time a print takes
            'others' => [],          // more printers: list of {printer, serial, tape_mm}
            'fail' => '',            // "print": write error; "jam": print looks fine, then error
        ];
    }

    /**
     * @param array<string, string|false> $env
     * @return array<string, mixed>
     */
    public static function stateFromEnv(array $env): array
    {
        $state = [];
        foreach (array_keys(self::defaults()) as $key) {
            $name = 'PTSIM_' . strtoupper($key);
            if (isset($env[$name]) && $env[$name] !== false) {
                $state[$key] = $env[$name];
            }
        }
        $file = $env['PTSIM_STATE'] ?? false;
        if (is_string($file) && is_file($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            if (is_array($json)) {
                $state = $json + $state;
            }
        }
        foreach (['tape_mm', 'media_type', 'tape_color', 'text_color', 'error', 'delay_ms'] as $key) {
            if (isset($state[$key])) {
                $state[$key] = (int) $state[$key];
            }
        }
        return $state;
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    public function run(array $argv): array
    {
        $code = $this->dispatch($argv);
        return ['code' => $code, 'stdout' => implode('', $this->out), 'stderr' => implode('', $this->err)];
    }

    private function say(string $text): void
    {
        $this->out[] = $text . "\n";
    }

    private function warn(string $text): void
    {
        $this->err[] = $text . "\n";
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        $args = [
            'version' => false, 'status' => false, 'json' => false, 'image' => null,
            'copies' => 1, 'chain' => false, 'precut' => false, 'full_cut' => false,
            'no_margin' => false, 'host' => null, 'usb' => false, 'serial' => null,
            'printer' => null, 'tape_width' => 0.0, 'list_connected' => false,
        ];
        $valueOptions = ['image', 'copies', 'host', 'serial', 'printer', 'tape-width', 'snmp-community'];
        $flagOptions = ['version', 'status', 'json', 'chain', 'precut', 'full-cut', 'no-margin',
            'usb', 'list-connected', 'high-resolution'];
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];
            if ($arg === '--') {
                $i++;
                continue;
            }
            if (strncmp($arg, '--', 2) !== 0) {
                $this->warn("ptouch: unexpected argument '$arg'");
                return 2;
            }
            $name = substr($arg, 2);
            $value = null;
            if (strpos($name, '=') !== false) {
                [$name, $value] = explode('=', $name, 2);
            }
            if (in_array($name, $valueOptions, true)) {
                if ($value === null) {
                    if ($i + 1 >= $count) {
                        $this->warn("ptouch: option --$name requires an argument");
                        return 2;
                    }
                    $value = $argv[++$i];
                }
                if ($name === 'copies') {
                    $args['copies'] = max(1, (int) $value);
                } elseif ($name === 'tape-width') {
                    $args['tape_width'] = (float) $value;
                } elseif ($name === 'snmp-community') {
                    // accepted, not simulated
                } else {
                    $args[$name] = $value;
                }
                continue;
            }
            if (in_array($name, $flagOptions, true)) {
                $args[str_replace('-', '_', $name)] = true;
                continue;
            }
            $this->warn("ptouch: unrecognized option '--$name'");
            return 2;
        }

        $model = $args['printer'];
        if ($model !== null && !isset(self::PRINTERS[$model]) && isset(self::PRINTERS['PT-' . $model])) {
            $model = 'PT-' . $model;
        }
        if ($model !== null && isset(self::PRINTERS[$model])) {
            $this->state['printer'] = $model;
        }
        if ($args['tape_width'] > 0) {
            $this->state['tape_mm'] = (int) $args['tape_width'];
        }
        if ($args['version']) {
            $this->say('ptouch ' . $this->state['version'] . '.0');
            return 0;
        }
        if ($args['list_connected']) {
            return $this->listConnected((bool) $args['json']);
        }
        if ($args['status']) {
            return $this->status((bool) $args['json'], $args);
        }
        if ($args['image'] === null) {
            return 0;
        }
        return $this->printImage($args);
    }

    /** @param array<string, mixed> $args */
    private function status(bool $json, array $args): int
    {
        $status = $this->statusData($args);
        if ($json) {
            $this->say((string) json_encode($status, JSON_PRETTY_PRINT));
        } else {
            foreach ($status as $key => $value) {
                if (!is_array($value)) {
                    $this->say($key . ' = ' . (string) $value);
                }
            }
        }
        return $status['connected'] ? 0 : 5;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function statusData(array $args): array
    {
        $state = $this->state;
        $printer = (string) $state['printer'];
        $serial = $args['serial'] ?? null;
        $connected = $printer !== 'none' && isset(self::PRINTERS[$printer]) && $state['mode'] !== 'access';
        if ($serial !== null && $serial !== '' && $serial !== $state['serial']) {
            $other = null;
            foreach ($this->others() as $candidate) {
                if (($candidate['serial'] ?? '') === $serial) {
                    $other = $candidate;
                }
            }
            if ($other === null) {
                $connected = false;
            } else {
                $state = $other + $state;
                $printer = (string) $state['printer'];
            }
        }
        [$maxPx, $dpi] = self::PRINTERS[$printer] ?? [0, 180];
        $tapePx = $this->tapePx($state, $dpi, $maxPx);
        $width = (int) $state['tape_mm'];
        $error = (int) $state['error'];
        $ready = $connected && $error === 0 && $width > 0 && $tapePx > 0
            && !in_array($state['mode'], ['plite', 'unsupported'], true);
        $message = '';
        if (!$connected) {
            $message = 'No printer found. Check the connection and that the printer is switched on.';
        } elseif ($state['mode'] === 'plite') {
            $message = 'The printer is in P-Lite mode. Set the mode switch to E.';
        } elseif ($state['mode'] === 'unsupported') {
            $message = 'This printer model is not supported.';
        } elseif ($width === 0) {
            $message = 'No tape cassette inserted.';
        } elseif ($error !== 0) {
            $message = sprintf('The printer reports error 0x%04x.', $error);
        }
        return [
            'connected' => $connected,
            'ready' => $ready,
            'model' => $connected ? $printer : null,
            'model_description' => $connected ? 'Brother ' . $printer : null,
            'model_code' => null,
            'serial' => $connected ? (string) $state['serial'] : null,
            'dpi' => $dpi,
            'max_px' => $maxPx,
            'tape_px' => $tapePx,
            'media_type' => $width === 0 ? 0 : (int) $state['media_type'],
            'media_type_label' => self::MEDIA_TYPES[(int) $state['media_type']] ?? 'unknown',
            'media_width_mm' => $width,
            'media_length_mm' => 0,
            'tape_color' => (int) $state['tape_color'],
            'text_color' => (int) $state['text_color'],
            'error_code' => $error,
            'errors' => [],
            'battery_level' => 4,
            'device_status' => $ready ? 'READY' : 'ERROR',
            'media_status' => $width > 0 ? 'Not Empty' : 'Empty',
            'message' => $message,
            'source' => 'vendor-status',
            'ptouch_version' => (string) $state['version'] . '.0',
        ];
    }

    /** @param array<string, mixed> $args */
    private function printImage(array $args): int
    {
        $png = Png::read((string) $args['image']);
        if ($png === null) {
            $this->say('failed to load image file');
            return 1;
        }
        $status = $this->statusData($args);
        $tapePx = (int) $status['tape_px'];
        if ($png['height'] > $tapePx) {
            $this->say(sprintf('image is too large (%dpx x %dpx)', $png['width'], $png['height']));
            $this->say(sprintf('maximum printing width for this tape is %dpx', $tapePx));
            return 0;
        }
        if ($this->state['fail'] === 'print') {
            $this->warn('write error: LIBUSB_ERROR_PIPE');
            return 2;
        }
        if ($this->state['delay_ms'] > 0) {
            usleep(((int) $this->state['delay_ms']) * 1000);
        }
        for ($i = 0; $i < (int) $args['copies']; $i++) {
            $this->say(sprintf('image size (%dpx x %dpx)', $png['width'], $png['height']));
            $this->store($png, $args, $i, $status);
        }
        if ($this->state['fail'] === 'jam' && $this->stateFile !== '') {
            $saved = json_decode((string) @file_get_contents($this->stateFile), true);
            $saved = is_array($saved) ? $saved : [];
            file_put_contents($this->stateFile, (string) json_encode(['error' => 0x0004, 'fail' => ''] + $saved));
        }
        return 0;
    }

    private function listConnected(bool $json): int
    {
        $printers = [];
        if ($this->state['printer'] !== 'none' && isset(self::PRINTERS[(string) $this->state['printer']])) {
            foreach (array_merge([$this->state], $this->others()) as $printer) {
                $printers[] = [
                    'model' => $printer['printer'],
                    'serial' => $printer['serial'],
                    'product_id' => null,
                    'usable' => ($printer['mode'] ?? 'normal') !== 'plite',
                ];
            }
        }
        if ($json) {
            $this->say((string) json_encode($printers, JSON_PRETTY_PRINT));
        } else {
            foreach ($printers as $printer) {
                $this->say($printer['model'] . "\tserial " . $printer['serial']);
            }
        }
        return $printers === [] ? 5 : 0;
    }

    /** @return list<array<string, mixed>> */
    private function others(): array
    {
        $others = $this->state['others'];
        if (is_string($others)) {
            $others = json_decode($others, true);
        }
        return array_values(array_filter(is_array($others) ? $others : [], static function ($p): bool {
            return is_array($p) && isset($p['printer'], $p['serial']);
        }));
    }

    /** @param array<string, mixed> $state */
    private function tapePx(array $state, int $dpi, int $maxPx): int
    {
        $mm = (int) $state['tape_mm'];
        if (!isset(self::TAPES[$mm])) {
            return 0;
        }
        return min($maxPx, self::TAPES[$mm][$dpi === 360 ? 1 : 0]);
    }

    /**
     * @param array<string, mixed> $png
     * @param array<string, mixed> $args
     * @param array<string, mixed> $status
     */
    private function store(array $png, array $args, int $copy, array $status): void
    {
        $dir = (string) $this->state['out'];
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $n = count(glob($dir . '/label-*.json') ?: []) + 1;
        $base = sprintf('%s/label-%04d', $dir, $n);
        if (isset($png['file'])) {
            copy((string) $png['file'], $base . '.png');
        }
        file_put_contents($base . '.json', json_encode([
            'n' => $n,
            'copy' => $copy + 1,
            'copies' => (int) $args['copies'],
            'chain' => (bool) $args['chain'],
            'precut' => (bool) $args['precut'],
            'full_cut' => (bool) $args['full_cut'],
            'no_margin' => (bool) $args['no_margin'],
            'width_px' => (int) $png['width'],
            'height_px' => (int) $png['height'],
            'tape_px' => (int) $status['tape_px'],
            'dpi' => (int) $status['dpi'],
            'black_px' => (int) $png['black'],
            'version' => (string) $this->state['version'],
        ], JSON_PRETTY_PRINT));
    }
}

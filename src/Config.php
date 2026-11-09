<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Settings: built-in defaults, then an optional config.php that returns an array,
 * then LABELER_<KEY> environment variables (key upper-cased).
 */
final class Config
{
    public const VERSION = '1.1.0-dev';

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @return array<string, mixed> */
    public static function defaults(string $root): array
    {
        return [
            // ptouch executable: a name on PATH or an absolute path.
            'ptouch_bin' => 'ptouch',
            // Seconds a single ptouch run may take before it is stopped.
            'timeout' => 60,
            // Printers to talk to. Each entry is either
            //   ['host' => '192.168.8.235', 'model' => '', 'community' => 'public', 'tape_width' => 0]
            // or ['usb' => true, 'serial' => '...'].
            'printers' => [
                'default' => [
                    'id' => 'default', 'host' => '', 'usb' => true, 'serial' => '',
                    'model' => '', 'community' => 'public', 'tape_width' => 0.0,
                ],
            ],
            // Default printer id from the printers map.
            'printer' => 'default',
            // Shorthand for a single network printer: host and optional model/tape.
            'printer_host' => '',
            'printer_model' => '',
            'printer_tape_width' => 0,
            'printer_community' => 'public',
            // Optional CIDR for network discovery (follow-up UI); empty: local /24.
            'discovery_cidr' => '',
            // USB serial of the default USB printer (legacy shorthand).
            'serial' => '',
            // Writable folder for the print log. Empty disables the log.
            'data_dir' => $root . '/data',
            // Folder with .ttf/.otf/.woff/.woff2 files offered in the font list.
            'fonts_dir' => $root . '/fonts',
            // Largest accepted label PNG in bytes.
            'max_upload_bytes' => 2000000,
            // Most copies per print request.
            'max_copies' => 50,
            // Entries kept in the print log.
            'log_entries' => 50,
            // Access token for this API. When set, every request except health needs
            // "Authorization: Bearer <token>". Always set it on a printer agent.
            'token' => '',
            // Printer agent to use instead of a local ptouch, e.g. http://printer-pi:8631,
            // and its token. Empty: print locally.
            'printer_url' => '',
            'printer_token' => '',
            // Password for the interface, plain text or a hash from PHP's password_hash()
            // (php -r 'echo password_hash("secret", PASSWORD_DEFAULT);'). Empty: no login.
            'password' => '',
            // Iconify API for the icon search (MDI, Simple Icons), queried by the browser.
            // Another Iconify server can be used; empty switches icons off.
            'icon_api' => 'https://api.iconify.design',
        ];
    }

    /**
     * @param array<string, string|false>|null $env environment, defaults to getenv()
     */
    public static function load(string $root, ?string $file = null, ?array $env = null): self
    {
        $values = self::defaults($root);
        $file = $file ?? $root . '/config.php';
        if (is_file($file)) {
            $fromFile = include $file;
            if (is_array($fromFile)) {
                foreach ($fromFile as $key => $value) {
                    if (array_key_exists($key, $values)) {
                        $values[$key] = $value;
                    }
                }
            }
        }
        $env = $env ?? getenv();
        foreach (array_keys($values) as $key) {
            $name = 'LABELER_' . strtoupper($key);
            if (isset($env[$name]) && $env[$name] !== false) {
                $values[$key] = $env[$name];
            }
        }
        foreach (['timeout', 'max_upload_bytes', 'max_copies', 'log_entries', 'printer_tape_width'] as $key) {
            $values[$key] = max(0, (int) $values[$key]);
        }
        foreach (['ptouch_bin', 'printer', 'printer_host', 'printer_model', 'printer_community', 'discovery_cidr', 'serial', 'data_dir', 'fonts_dir', 'token', 'printer_url', 'printer_token', 'icon_api'] as $key) {
            $values[$key] = trim((string) $values[$key]);
        }
        $values['printers'] = self::printers($values['printers'], (string) $values['printer_community']);
        $usingDefaultUsb = count($values['printers']) === 1
            && isset($values['printers']['default'])
            && $values['printers']['default']['usb']
            && $values['printers']['default']['serial'] === '';
        if ($usingDefaultUsb && $values['printer_host'] !== '') {
            $values['printers']['default'] = self::printer([
                'host' => $values['printer_host'],
                'model' => $values['printer_model'],
                'tape_width' => $values['printer_tape_width'],
                'community' => $values['printer_community'],
            ], 'default', (string) $values['printer_community']);
        } elseif ($usingDefaultUsb && $values['serial'] !== '') {
            $values['printers']['default'] = self::printer([
                'usb' => true,
                'serial' => $values['serial'],
            ], 'default', (string) $values['printer_community']);
        }
        if ($values['printers'] === []) {
            if ($values['printer_host'] !== '') {
                $values['printers']['default'] = self::printer([
                    'host' => $values['printer_host'],
                    'model' => $values['printer_model'],
                    'tape_width' => $values['printer_tape_width'],
                    'community' => $values['printer_community'],
                ], 'default', (string) $values['printer_community']);
            } elseif ($values['serial'] !== '') {
                $values['printers']['default'] = self::printer([
                    'usb' => true,
                    'serial' => $values['serial'],
                ], 'default', (string) $values['printer_community']);
            }
        }
        if ($values['printers'] === []) {
            // Preserve the old default: the first Brother printer on USB.
            $values['printers']['default'] = self::printer([
                'usb' => true,
            ], 'default', (string) $values['printer_community']);
        }
        if ($values['printer'] === '' || !isset($values['printers'][$values['printer']])) {
            $values['printer'] = $values['printers'] === [] ? '' : (string) array_key_first($values['printers']);
        }
        if (!preg_match('#^https?://[^\s"\'<>?\#]+$#', $values['printer_url'])) {
            $values['printer_url'] = '';
        }
        if (!preg_match('#^https?://[^\s"\'<>]+$#', $values['icon_api'])) {
            $values['icon_api'] = '';
        }
        return new self($values);
    }

    /**
     * @param mixed $raw value from config.php or LABELER_PRINTERS (JSON string)
     * @return array<string, array<string, mixed>>
     */
    private static function printers($raw, string $defaultCommunity): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $printers = [];
        foreach ($raw as $id => $printer) {
            $id = (string) $id;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $id) || !is_array($printer)) {
                continue;
            }
            $printers[$id] = self::printer($printer, $id, $defaultCommunity);
        }
        return $printers;
    }

    /**
     * @param array<string, mixed> $printer
     * @return array<string, mixed>
     */
    private static function printer(array $printer, string $id, string $defaultCommunity): array
    {
        $host = trim((string) ($printer['host'] ?? ''));
        $usb = !empty($printer['usb']) || $host === '';
        $model = trim((string) ($printer['model'] ?? ''));
        $serial = trim((string) ($printer['serial'] ?? ''));
        $community = trim((string) ($printer['community'] ?? $defaultCommunity));
        $tapeWidth = (float) ($printer['tape_width'] ?? 0);
        return [
            'id' => $id,
            'host' => $usb ? '' : $host,
            'usb' => $usb,
            'serial' => $serial,
            'model' => $model,
            'community' => $community === '' ? 'public' : $community,
            'tape_width' => $tapeWidth > 0 ? $tapeWidth : 0.0,
        ];
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->values[$key];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}

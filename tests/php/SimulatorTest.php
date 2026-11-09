<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Sim\Simulator;

/**
 * The simulator itself: it is the reference the other tests rely on, so it is tested too.
 */
final class SimulatorTest extends TestCase
{
    /** @param list<string> $args */
    private function sim(array $args, array $state = []): array
    {
        return (new Simulator($state + ['out' => $this->tmp . '/printed']))->run($args);
    }

    public function testVersion(): void
    {
        $this->assertSame("ptouch 1.9.0\n", $this->sim(['--version'])['stdout']);
    }

    public function testStatusJson(): void
    {
        $r = $this->sim(['--status', '--json'], ['printer' => 'PT-P900W', 'tape_mm' => 24]);
        $this->assertSame(0, $r['code']);
        $status = json_decode($r['stdout'], true);
        $this->assertTrue($status['connected']);
        $this->assertTrue($status['ready']);
        $this->assertSame('PT-P900W', $status['model']);
        $this->assertSame(360, $status['dpi']);
        $this->assertSame(320, $status['tape_px']);
        $this->assertSame(24, $status['media_width_mm']);
        $this->assertSame(0, $status['error_code']);
    }

    public function testStatusWithoutPrinter(): void
    {
        $r = $this->sim(['--status', '--json'], ['printer' => 'none']);
        $this->assertSame(5, $r['code']);
        $status = json_decode($r['stdout'], true);
        $this->assertFalse($status['connected']);
        $this->assertFalse($status['ready']);
        $this->assertStringContainsString('No printer found', $status['message']);
    }

    public function testStatusProblems(): void
    {
        $this->assertFalse(json_decode($this->sim(['--status', '--json'], ['tape_mm' => 0])['stdout'], true)['ready']);
        $status = json_decode($this->sim(['--status', '--json'], ['tape_mm' => 12, 'error' => 0x1000])['stdout'], true);
        $this->assertFalse($status['ready']);
        $this->assertSame(0x1000, $status['error_code']);
        $this->assertStringContainsString('No tape', json_decode($this->sim(['--status', '--json'], ['tape_mm' => 0])['stdout'], true)['message']);
        $this->assertStringContainsString('P-Lite', json_decode($this->sim(['--status', '--json'], ['mode' => 'plite'])['stdout'], true)['message']);
    }

    public function testStatusAutoDetectsModelAndTape(): void
    {
        $status = json_decode($this->sim(['--status', '--json', '--printer', 'PT-P750W', '--tape-width', '9'])['stdout'], true);
        $this->assertSame('PT-P750W', $status['model']);
        $this->assertSame(9, $status['media_width_mm']);
        $this->assertSame(52, $status['tape_px']);
    }

    public function testListConnected(): void
    {
        $r = $this->sim(['--list-connected', '--json']);
        $this->assertSame(0, $r['code']);
        $list = json_decode($r['stdout'], true);
        $this->assertCount(1, $list);
        $this->assertSame('PT-P700', $list[0]['model']);
        $this->assertSame('000F1Z401234', $list[0]['serial']);
        $this->assertTrue($list[0]['usable']);

        $others = '[{"printer": "PT-P750W", "serial": "B2", "tape_mm": 24}, "junk"]';
        $list = json_decode($this->sim(['--list-connected', '--json'], ['others' => $others])['stdout'], true);
        $this->assertCount(2, $list);
        $this->assertSame(5, $this->sim(['--list-connected', '--json'], ['printer' => 'none'])['code']);
    }

    public function testPrintStoresRecords(): void
    {
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 76));
        $r = $this->sim(
            ['--image', $file, '--copies', '2', '--chain', '--precut', '--full-cut', '--no-margin'],
            ['tape_mm' => 12]
        );
        $this->assertSame(0, $r['code']);
        $printed = $this->printed();
        $this->assertCount(2, $printed);
        $this->assertSame(2, $printed[0]['copies']);
        $this->assertTrue($printed[0]['chain']);
        $this->assertTrue($printed[0]['precut']);
        $this->assertTrue($printed[0]['full_cut']);
        $this->assertTrue($printed[0]['no_margin']);
        $this->assertSame(76, $printed[0]['height_px']);
        $this->assertFileExists($this->tmp . '/printed/label-0001.png');
    }

    public function testPrintTooLarge(): void
    {
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 128));
        $r = $this->sim(['--image', $file], ['tape_mm' => 12]);
        $this->assertSame(0, $r['code'], 'like ptouch-print, the simulator ignores the print result');
        $this->assertStringContainsString('image is too large', $r['stdout']);
    }

    public function testMissingImageAndFailure(): void
    {
        $this->assertSame(1, $this->sim(['--image', $this->tmp . '/none.png'])['code']);
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 76));
        $r = $this->sim(['--image', $file], ['fail' => 'print']);
        $this->assertSame(2, $r['code']);
        $this->assertStringContainsString('write error', $r['stderr']);
    }

    public function testJamWritesStateFile(): void
    {
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 76));
        $state = $this->tmp . '/state.json';
        file_put_contents($state, '{"fail": "jam"}');
        $sim = new Simulator(['out' => $this->tmp . '/out', 'fail' => 'jam'], $state);
        $this->assertSame(0, $sim->run(['--image', $file])['code']);
        $saved = json_decode((string) file_get_contents($state), true);
        $this->assertSame(0x0004, $saved['error']);
    }

    public function testUnknownOption(): void
    {
        $r = $this->sim(['--fly']);
        $this->assertSame(2, $r['code']);
        $this->assertStringContainsString("unrecognized option '--fly'", $r['stderr']);
        $this->assertSame(2, $this->sim(['--image'])['code']);
    }

    public function testStateFromEnv(): void
    {
        $state = Simulator::stateFromEnv(['PTSIM_VERSION' => '1.2', 'PTSIM_TAPE_MM' => '9', 'PTSIM_MODE' => false]);
        $this->assertSame('1.2', $state['version']);
        $this->assertSame(9, $state['tape_mm']);
        $this->assertArrayNotHasKey('mode', $state);
        $this->assertArrayHasKey('tape_mm', Simulator::defaults());
    }

    public function testStateFileOverridesTheEnvironment(): void
    {
        $state = $this->tmp . '/state.json';
        file_put_contents($state, '{"tape_mm": 9, "printer": "PT-P750W"}');
        $loaded = Simulator::stateFromEnv(['PTSIM_STATE' => $state, 'PTSIM_TAPE_MM' => '24']);
        $this->assertSame(9, $loaded['tape_mm']);
        $this->assertSame('PT-P750W', $loaded['printer']);
    }

    public function testArgumentForms(): void
    {
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(60, 76));
        $this->assertSame(0, $this->sim(['--image=' . $file, '--copies=2'], ['tape_mm' => 12])['code']);
        $this->assertCount(2, $this->printed());
        $this->assertSame(2, $this->sim(['stray'])['code']);
        $this->assertSame(0, $this->sim(['--'])['code']);
        $this->assertSame(0, $this->sim(['--copies', '2'])['code']);
    }

    public function testShortPrinterNameAndHumanStatus(): void
    {
        $status = $this->sim(['--status', '--json', '--printer', 'P750W']);
        $this->assertSame('PT-P750W', json_decode($status['stdout'], true)['model']);
        $human = $this->sim(['--status']);
        $this->assertStringContainsString('model = PT-P700', $human['stdout']);
    }

    public function testSerialSelection(): void
    {
        $others = '[{"printer": "PT-P750W", "serial": "B2", "tape_mm": 24}]';
        $status = json_decode($this->sim(['--status', '--json', '--serial', 'B2'], ['others' => $others])['stdout'], true);
        $this->assertSame('PT-P750W', $status['model']);
        $missing = json_decode($this->sim(['--status', '--json', '--serial', 'NOPE'], ['others' => $others])['stdout'], true);
        $this->assertFalse($missing['connected']);
    }

    public function testUnsupportedMode(): void
    {
        $status = json_decode($this->sim(['--status', '--json'], ['mode' => 'unsupported'])['stdout'], true);
        $this->assertStringContainsString('not supported', $status['message']);
    }

    public function testScan(): void
    {
        $discovered = [['host' => '192.0.2.10', 'model' => 'PT-P900W', 'serial' => 'S1', 'media_width_mm' => 24, 'ready' => true]];
        $r = $this->sim(['--scan', '192.0.2.0/24', '--json'], ['discovered' => $discovered]);
        $this->assertSame($discovered, json_decode($r['stdout'], true));
        $human = $this->sim(['--scan', '192.0.2.0/24'], ['discovered' => $discovered]);
        $this->assertStringContainsString('PT-P900W', $human['stdout']);
        $this->assertSame([], json_decode($this->sim(['--scan', '192.0.2.0/24', '--json'], ['discovered' => 'not json'])['stdout'], true));
    }

    public function testHumanListConnected(): void
    {
        $r = $this->sim(['--list-connected']);
        $this->assertStringContainsString('PT-P700', $r['stdout']);
    }

    public function testDelayAndNoOutputFolder(): void
    {
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(60, 76));
        $sim = new Simulator(['out' => '', 'delay_ms' => 1], '');
        $this->assertSame(0, $sim->run(['--image', $file])['code']);
    }

    public function testPngFiltersAndBlank(): void
    {
        $plte = "\xff\xff\xff\0\0\0";
        $ihdr = pack('NNCCCCC', 8, 1, 1, 3, 0, 0, 0);
        for ($filter = 0; $filter <= 4; $filter++) {
            $file = $this->tmp . '/filter-' . $filter . '.png';
            file_put_contents($file, self::pngChunks($ihdr, $plte, chr($filter) . "\xaa"));
            $png = \Labeler\Sim\Png::read($file);
            $this->assertNotNull($png, 'filter ' . $filter);
            $this->assertTrue($png['palette']);
        }
        $blank = $this->tmp . '/blank.png';
        file_put_contents($blank, \Labeler\Sim\Png::blank(8, 1));
        $this->assertSame(0, \Labeler\Sim\Png::read($blank)['black']);
    }

    public function testPngRejectsWrongImages(): void
    {
        $file = $this->tmp . '/truecolor.png';
        file_put_contents($file, self::pngChunks(pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0), null, "\0\0\0\0"));
        $this->assertFalse(\Labeler\Sim\Png::read($file)['palette']);

        $file = $this->tmp . '/no-ihdr.png';
        file_put_contents($file, "\x89PNG\r\n\x1a\n");
        $this->assertNull(\Labeler\Sim\Png::read($file));

        $file = $this->tmp . '/bad-idat.png';
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        file_put_contents(
            $file,
            "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 8, 1, 1, 3, 0, 0, 0))
            . $chunk('PLTE', "\xff\xff\xff\0\0\0") . $chunk('IDAT', 'not gzip') . $chunk('IEND', '')
        );
        $this->assertNull(\Labeler\Sim\Png::read($file));
    }
}

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
}

<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Log;
use Labeler\Ptouch;

final class PtouchTest extends TestCase
{
    /** @param array<string, mixed> $printer */
    private function withPrinters(array $printers, string $default = 'default', string $bin = ''): Ptouch
    {
        return new Ptouch($bin ?: self::simBin(), $printers, $default, 20);
    }

    /** @return array<string, mixed> */
    private static function usbPrinter(string $serial = '', string $model = ''): array
    {
        return [
            'id' => 'default', 'host' => '', 'usb' => true, 'serial' => $serial,
            'model' => $model, 'community' => 'public', 'tape_width' => 0.0,
        ];
    }

    /** @return array<string, mixed> */
    private static function networkPrinter(string $host, string $model = '', float $tape = 0.0): array
    {
        return [
            'id' => 'default', 'host' => $host, 'usb' => false, 'serial' => '',
            'model' => $model, 'community' => 'public', 'tape_width' => $tape,
        ];
    }

    public function testInfo(): void
    {
        $this->simulate(['tape_mm' => 24, 'tape_color' => 6, 'text_color' => 8]);
        $info = $this->withPrinters(['default' => self::usbPrinter()])->info();
        $this->assertTrue($info['ready'], $info['message']);
        $this->assertSame('PT-P700', $info['model']);
        $this->assertSame(24, $info['media_width_mm']);
        $this->assertSame(128, $info['tape_px']);
        $this->assertSame(128, $info['max_px']);
        $this->assertSame(180, $info['dpi']);
        $this->assertSame(['code' => 6, 'name' => 'Yellow'], $info['tape_color']);
        $this->assertSame(['code' => 8, 'name' => 'Black'], $info['text_color']);
        $this->assertSame(0, $info['error_code']);
        $this->assertSame('000F1Z401234', $info['serial']);
        $this->assertSame('', $info['message']);
    }

    public function test360DpiPrinter(): void
    {
        $this->simulate(['printer' => 'PT-P900W', 'tape_mm' => 36]);
        $info = $this->withPrinters(['default' => self::usbPrinter()])->info();
        $this->assertSame(360, $info['dpi']);
        $this->assertSame(454, $info['tape_px']);
        $this->assertSame(454, $info['max_px']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}> */
    public static function problems(): array
    {
        return [
            'no printer' => [['printer' => 'none'], 'No printer found', false],
            'p-lite' => [['mode' => 'plite'], 'P-Lite mode', true],
            'unsupported' => [['mode' => 'unsupported'], 'not supported', true],
            'no access' => [['mode' => 'access'], 'No printer found', false],
            'no tape' => [['tape_mm' => 0], 'No tape cassette', true],
            'cover open' => [['error' => 0x1000], 'The cover is open: close it.', true],
            'two errors' => [['error' => 0x2004], 'Cutter jam', true],
            'unknown bit' => [['error' => 0x0002], 'The printer reports error 0x0002.', true],
        ];
    }

    /** @dataProvider problems */
    public function testReportsProblems(array $state, string $message, bool $connected): void
    {
        $this->simulate($state);
        $info = $this->withPrinters(['default' => self::usbPrinter()])->info();
        $this->assertFalse($info['ready']);
        $this->assertSame($connected, $info['connected']);
        $this->assertStringContainsString($message, $info['message']);
    }

    public function testMissingPtouch(): void
    {
        $printer = $this->withPrinters(['default' => self::usbPrinter()], 'default', $this->tmp . '/missing');
        $this->assertNull($printer->version());
        $info = $printer->info();
        $this->assertFalse($info['ready']);
        $this->assertStringContainsString('not found', $info['message']);
    }

    public function testVersionAndFeatures(): void
    {
        $this->simulate(['version' => '1.2']);
        $printer = $this->withPrinters(['default' => self::usbPrinter()]);
        $this->assertSame('1.2', $printer->version());
        $this->assertTrue($printer->features()['copies']);
        $this->assertTrue($printer->features()['network']);
    }

    public function testInfoThroughTheProcess(): void
    {
        $this->simulate(['tape_mm' => 9]);
        $info = $this->withPrinters(['default' => self::usbPrinter()])->info();
        $this->assertTrue($info['ready']);
        $this->assertSame(52, $info['tape_px']);
    }

    public function testWithPrinter(): void
    {
        $this->simulate(['tape_mm' => 12, 'others' => json_encode([['printer' => 'PT-P900W', 'serial' => 'B2', 'tape_mm' => 24]])]);
        $printers = [
            'one' => self::usbPrinter(),
            'two' => ['id' => 'two', 'host' => '', 'usb' => true, 'serial' => 'B2', 'model' => '', 'community' => 'public', 'tape_width' => 0.0],
        ];
        $printer = $this->withPrinters($printers, 'one');
        $this->assertSame('one', $printer->currentId());
        $this->assertSame(76, $printer->info()['tape_px']);
        $other = $printer->withPrinter('two');
        $this->assertSame('two', $other->currentId());
        $this->assertSame(320, $other->info()['tape_px']);
        $this->assertSame('one', $printer->currentId(), 'the original keeps its printer');
        $this->assertSame('__selected', $printer->withPrinter('unknown')->currentId());
        $this->assertSame('two', $printer->withPrinter('B2')->currentId());
    }

    public function testPrintImage(): void
    {
        $this->simulate(['tape_mm' => 12]);
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 76));
        $printer = $this->withPrinters(['default' => self::networkPrinter('192.0.2.1')]);
        $result = $printer->printImage($file, 2, true, true);
        $this->assertTrue($result['ok'], $result['output']);
        $printed = $this->printed();
        $this->assertCount(2, $printed);
        $this->assertSame(2, $printed[0]['copies']);
        $this->assertTrue($printed[0]['chain']);
        $this->assertTrue($printed[0]['precut']);
        $this->assertTrue($printed[0]['full_cut']);
        $this->assertTrue($printed[0]['no_margin']);
    }

    public function testPrintImageTooLarge(): void
    {
        $this->simulate(['tape_mm' => 12]);
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 128));
        $result = $this->withPrinters(['default' => self::usbPrinter()])->printImage($file);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('too large', $result['output']);
    }

    public function testPrintImageFailure(): void
    {
        $this->simulate(['tape_mm' => 12, 'fail' => 'print']);
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 76));
        $result = $this->withPrinters(['default' => self::usbPrinter()])->printImage($file);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('write error', $result['output']);
    }

    public function testPrintImageWithOverrides(): void
    {
        $this->simulate(['tape_mm' => 12]);
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(200, 320));
        $log = new Log($this->tmp . '/data', 5);
        $printer = new Ptouch(
            self::simBin(),
            ['default' => self::networkPrinter('192.0.2.1', 'P900W', 24.0)],
            'default',
            20,
            $log
        );
        $result = $printer->printImage($file);
        $this->assertTrue($result['ok'], $result['output']);
        $args = implode(' ', $log->entries()[0]['args']);
        $this->assertStringContainsString('--printer P900W', $args);
        $this->assertStringContainsString('--tape-width 24', $args);
        $this->assertStringContainsString('--snmp-community public', $args);
        $this->assertSame(320, $this->printed()[0]['tape_px']);
    }

    public function testUnknownColors(): void
    {
        $this->simulate(['tape_mm' => 12, 'tape_color' => 0x99, 'text_color' => 0x66]);
        $info = $this->withPrinters(['default' => self::usbPrinter()])->info();
        $this->assertSame(['code' => 0x99, 'name' => 'Unknown'], $info['tape_color']);
        $this->assertSame(['code' => 0x66, 'name' => 'Unknown'], $info['text_color']);
    }

    public function testPrintersWhenNoneConnected(): void
    {
        $this->simulate(['printer' => 'none']);
        $this->assertSame([], $this->withPrinters([])->printers());
    }

    public function testCurrentIdAndEmptySelection(): void
    {
        $printer = $this->withPrinters(['default' => self::usbPrinter()]);
        $this->assertSame('default', $printer->currentId());
        $this->assertSame('default', $printer->withPrinter('')->currentId());
    }

    public function testNoPrinterConfigured(): void
    {
        $printer = $this->withPrinters([]);
        $this->assertSame('', $printer->currentId());
        $info = $printer->info();
        $this->assertFalse($info['connected']);
        $this->assertStringContainsString('No printer configured', $info['message']);
        $this->assertFalse($printer->printImage($this->tmp . '/none.png')['ok']);
    }

    public function testPrintersFromUsb(): void
    {
        $this->simulate(['tape_mm' => 12]);
        $printers = $this->withPrinters([])->printers();
        $this->assertCount(1, $printers);
        $this->assertSame('usb:000F1Z401234', $printers[0]['id']);
        $this->assertSame('PT-P700', $printers[0]['model']);
        $this->assertTrue($printers[0]['usable']);
    }

    public function testPrintersFromConfig(): void
    {
        $this->simulate(['tape_mm' => 12]);
        $printers = $this->withPrinters(['office' => self::networkPrinter('192.0.2.1')], 'office')->printers();
        $this->assertCount(1, $printers);
        $this->assertSame('office', $printers[0]['id']);
        $this->assertSame('network', $printers[0]['kind']);
        $this->assertSame('192.0.2.1', $printers[0]['host']);
    }

    public function testWithPrinterUsbPrefixAndArbitrarySerial(): void
    {
        $printers = [
            'one' => self::usbPrinter(),
            'two' => ['id' => 'two', 'host' => '', 'usb' => true, 'serial' => 'B2', 'model' => '', 'community' => 'public', 'tape_width' => 0.0],
        ];
        $printer = $this->withPrinters($printers, 'one');
        $this->assertSame('two', $printer->withPrinter('usb:B2')->currentId());
        $this->assertSame('__selected', $printer->withPrinter('other')->currentId());
    }

    public function testParseStatusBranches(): void
    {
        $method = new \ReflectionMethod(Ptouch::class, 'parseStatus');
        $method->setAccessible(true);
        $config = self::networkPrinter('192.0.2.1');
        $notConnected = $method->invoke(null, ['connected' => false], 1, '', $config);
        $this->assertStringContainsString('No printer found', $notConnected['message']);
        $notReady = $method->invoke(
            null,
            ['connected' => true, 'ready' => false, 'media_width_mm' => 12, 'media_type' => 1],
            0,
            '',
            $config
        );
        $this->assertSame('The printer is not ready.', $notReady['message']);
        $missing = $method->invoke(null, ['connected' => false], 127, '', $config);
        $this->assertStringContainsString('ptouch was not found', $missing['message']);
        $fromOutput = $method->invoke(null, ['connected' => false], 1, "Error: boom
more
", $config);
        $this->assertSame('boom', $fromOutput['message']);
        $odd = $method->invoke(
            null,
            [
                'connected' => true, 'ready' => true, 'model' => null, 'serial' => null,
                'media_width_mm' => 'x', 'media_type' => 'x', 'dpi' => 'x',
                'max_px' => 'x', 'tape_px' => 'x', 'error_code' => 'x',
                'tape_color' => null, 'text_color' => null,
            ],
            0,
            '',
            $config
        );
        $this->assertNull($odd['media_width_mm']);
        $this->assertSame(180, $odd['dpi']);
        $this->assertNull($odd['max_px']);
        $this->assertNull($odd['tape_px']);
        $this->assertSame(0, $odd['error_code']);
        $this->assertNull($odd['tape_color']);
        $this->assertNull($odd['model']);
    }

    public function testParseErrorBranches(): void
    {
        $method = new \ReflectionMethod(Ptouch::class, 'parseError');
        $method->setAccessible(true);
        $config = self::networkPrinter('192.0.2.1', 'P900W');
        $missing = $method->invoke(null, 127, 'whatever', $config);
        $this->assertStringContainsString('ptouch was not found', $missing['message']);
        $this->assertSame('ptouch did not return a status.', $method->invoke(null, 1, '', $config)['message']);
        $this->assertSame('boom', $method->invoke(null, 1, "Error: boom
", $config)['message']);
    }

    public function testColorRejectsNonNumericCodes(): void
    {
        $method = new \ReflectionMethod(Ptouch::class, 'color');
        $method->setAccessible(true);
        $this->assertNull($method->invoke(null, [], null));
        $this->assertNull($method->invoke(null, [], 'abc'));
    }

    public function testUsbPrintersWithOddOutput(): void
    {
        $bad = $this->tmp . '/bad-ptouch';
        file_put_contents($bad, "#!/bin/sh
printf 'not json'");
        chmod($bad, 0755);
        $this->assertSame([], $this->withPrinters([], '', $bad)->printers());

        $scalar = $this->tmp . '/scalar-ptouch';
        file_put_contents($scalar, "#!/bin/sh
printf '{\"bad\": 1}'");
        chmod($scalar, 0755);
        $this->assertSame([], $this->withPrinters([], '', $scalar)->printers());
    }

    public function testDecodeErrors(): void
    {
        $this->assertSame([], Ptouch::decodeErrors(0));
        $this->assertSame(
            ['No tape: insert a tape cassette.', 'Cutter jam: open the cover and remove the jammed tape.'],
            Ptouch::decodeErrors(0x0005)
        );
        $this->assertSame(['The printer reports error 0x0002.'], Ptouch::decodeErrors(0x0002));
    }

    public function testLogsPrintJobs(): void
    {
        $this->simulate(['tape_mm' => 12]);
        $log = new Log($this->tmp . '/data', 5);
        $file = $this->tmp . '/label.png';
        file_put_contents($file, self::labelPng(180, 76));
        $printer = new Ptouch(self::simBin(), ['default' => self::networkPrinter('192.0.2.1')], 'default', 20, $log);
        $printer->printImage($file);
        $entries = $log->entries();
        $this->assertCount(1, $entries);
        $this->assertSame(0, $entries[0]['code']);
        $this->assertStringContainsString('--image', implode(' ', $entries[0]['args']));
    }
}

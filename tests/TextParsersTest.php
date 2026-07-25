<?php

namespace TextAtAnyCost\Tests;

use PHPUnit\Framework\TestCase;
use TextAtAnyCost\Pdf;
use TextAtAnyCost\Rtf;
use TextAtAnyCost\ZippedXml;

class TextParsersTest extends TestCase
{
    public function testDocx2Text()
    {
        $file = __DIR__ . '/fixtures/dummy.docx';
        $text = ZippedXml::docx2text($file);
        
        $this->assertNotNull($text);
        $this->assertStringContainsString('Hello World', trim($text));
    }

    public function testOdt2Text()
    {
        $file = __DIR__ . '/fixtures/dummy.odt';
        $text = ZippedXml::odt2text($file);
        
        $this->assertNotNull($text);
        $this->assertStringContainsString('Hello ODT', trim($text));
    }

    public function testRtf2Text()
    {
        $file = __DIR__ . '/fixtures/dummy.rtf';
        $text = Rtf::rtf2text($file);
        
        $this->assertNotNull($text);
        $this->assertStringContainsString('Hello RTF', trim($text));
    }

    public function testPdf2Text()
    {
        $file = __DIR__ . '/fixtures/dummy.pdf';
        $text = Pdf::pdf2text($file);
        
        $this->assertNotNull($text);
        $this->assertStringContainsString('Hello PDF', trim($text));
    }
}

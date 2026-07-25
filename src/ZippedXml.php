<?php

namespace TextAtAnyCost;

/**
 * Class ZippedXml
 *
 * @package TextAtAnyCost
 */
class ZippedXml extends AbstractTextParser
{
    const FILE_DOCX = 'word/document.xml';
    const FILE_ODT = 'content.xml';

    /**
     * @param string $filename
     *
     * @return string|null
     */
    public static function odt2text($filename)
    {
        return (new self($filename, self::FILE_ODT))->parse();
    }

    /**
     * @param string $filename
     *
     * @return string|null
     */
    public static function docx2text($filename)
    {
        return (new self($filename, self::FILE_DOCX))->parse();
    }

    /**
     * @param $filename
     *
     * @param string $contentFile
     */
    public function __construct($filename, $contentFile = self::FILE_ODT)
    {
        $zip = new \ZipArchive();

        if ($zip->open($filename)) {
            if (($index = $zip->locateName($contentFile)) !== false) {
                $this->data = $zip->getFromIndex($index);
            }

            $zip->close();
        }
    }

    /**
     * @return string|null
     */
    public function parse()
    {
        $xml = new \DOMDocument();
        $xml->loadXML($this->data, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);

        $content = preg_replace(
            [
                "/(<text:s\/>|<\/w:tc><w:tc>|<\/w:r><\/w:p>|<text:p.*>)/Usi",
                "/<.*>/Usi"
            ],
            [" ", ""],
            $xml->saveXML()
        );
        $enc = mb_detect_encoding($content, "UTF-8, Windows-1251, Windows-1252, Windows-1254");

        if ($enc !== "UTF-8") {
            $content = mb_convert_encoding($content, "UTF-8", $enc);
        }

        return empty($content) ? null : $content;
    }
}

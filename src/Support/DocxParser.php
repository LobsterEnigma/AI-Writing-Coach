<?php

namespace Support;

use RuntimeException;
use ZipArchive;

class DocxParser
{
    public static function extractText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the uploaded document.');
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false) {
            throw new RuntimeException('Document structure is invalid.');
        }

        $xmlContent = preg_replace('/<w:p[^>]*>/', "\n", $xmlContent);
        $text = strip_tags($xmlContent);
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}

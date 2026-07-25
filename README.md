TextAtAnyCost
=============

**Note: This project is an archived, historical example of parsing binary document formats natively in PHP.**

A lightweight, zero-dependency PHP library for extracting raw text from various office and binary document formats (DOC, PPT, PDF, RTF, DOCX, ODT). 

### How it works
This library attempts to parse the binary streams (like Microsoft's legacy Compound File Binary Format) and raw hex/byte representations directly in PHP to strip out the text. For zipped XML formats (like DOCX and ODT), it uses `ZipArchive` and regular expressions to extract text from the internal XML nodes.

### Requirements
* PHP 8.1+
* `ext-zip`
* `ext-dom`
* `ext-zlib`

### Installation
```bash
composer require matasarei/text-at-any-cost
```

### Usage
```php
use TextAtAnyCost\Doc;
use TextAtAnyCost\Pdf;
use TextAtAnyCost\ZippedXml;

// Extract text from legacy .doc
$docText = Doc::doc2text('path/to/file.doc');

// Extract text from PDF
$pdfText = Pdf::pdf2text('path/to/file.pdf');

// Extract text from modern .docx
$docxText = ZippedXml::docx2text('path/to/file.docx');
```

---

## ⚠️ Why you probably shouldn't use this in production today

This library was originally written around 2009. While it is incredibly fast and memory-efficient compared to heavy DOM-building libraries, extracting text from complex formats like PDF or legacy binary DOC using native PHP streams and regular expressions is extremely brittle. 

It will likely fail or output garbage characters when encountering:
* Documents with complex layouts, nested tables, or embedded objects
* Unconventional text encodings
* Newer revisions of the PDF or Microsoft Office specifications

### What you should use instead

If you need robust text extraction in a modern application, you should rely on established, community-maintained libraries or native C/C++ CLI tools:

#### Modern PHP Libraries
* **PDF:** Use [Smalot/PdfParser](https://github.com/smalot/pdfparser)
* **Word/Office Formats:** Use [PHPOffice/PHPWord](https://github.com/PHPOffice/PHPWord)

#### Faster, Lightweight CLI Alternatives
For the same blazing-fast performance without the memory overhead of a massive PHP library, delegate the extraction to native system binaries using `shell_exec()` or Symfony Process:

* **PDF:** `pdftotext` (part of the `poppler-utils` package)
* **Legacy DOC:** `antiword` or `catdoc`
* **Modern DOCX/ODT:** `docx2txt`
* **Unified (All formats):** `Apache Tika` (requires Java) or `Pandoc`

Example of modern CLI extraction:
```php
$safePath = escapeshellarg('path/to/file.pdf');
$text = shell_exec("pdftotext {$safePath} -");
```

### Credits
Original Author: Alexey Rembish (alex@rembish.ru)

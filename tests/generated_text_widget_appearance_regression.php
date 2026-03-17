<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../fpdm.php';
}

function fail($message) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_true($condition, $message) {
    if (!$condition) {
        fail($message);
    }
}

function encode_field_hex_value($value, $isUTF8) {
    if ($isUTF8) {
        return strtoupper(bin2hex("\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', $value)));
    }

    return strtoupper(bin2hex($value));
}

function encode_appearance_hex_value($value, $isUTF8) {
    if ($isUTF8) {
        return strtoupper(bin2hex(iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value)));
    }

    return strtoupper(bin2hex($value));
}

function find_pdf_object_by_id($output, $objectId) {
    $pattern = '/\b' . preg_quote((string) $objectId, '/') . '\s+0\s+obj\s*(.*?)\bendobj\b/s';
    if (preg_match($pattern, $output, $match) !== 1) {
        return null;
    }

    return $match[1];
}

function find_pdf_object_by_field_name($output, $fieldName) {
    $needle = '/T (' . $fieldName . ')';
    $fieldPos = strpos($output, $needle);
    if ($fieldPos === false) {
        return null;
    }

    $objectStart = strrpos(substr($output, 0, $fieldPos), ' obj');
    if ($objectStart === false) {
        return null;
    }

    $lineStart = strrpos(substr($output, 0, $objectStart), "\n");
    if ($lineStart === false) {
        $lineStart = 0;
    } else {
        $lineStart += 1;
    }

    if (preg_match('/^(\d+)\s+0\s+obj\b/', substr($output, $lineStart), $match) !== 1) {
        return null;
    }

    $objectEnd = strpos($output, 'endobj', $fieldPos);
    if ($objectEnd === false) {
        return null;
    }

    return array(
        'id' => intval($match[1]),
        'body' => substr($output, $lineStart, $objectEnd + strlen('endobj') - $lineStart),
    );
}

$fpdmClass = class_exists('\\Magicplan\\Fpdm\\FPDM') ? '\\Magicplan\\Fpdm\\FPDM' : 'FPDM';
$templatePdf = __DIR__ . '/../src/template.pdf';
$isUTF8 = true;
$fields = array(
    'name' => 'Jörg Müller',
    'address' => 'Straße 1',
    'city' => 'Köln',
    'phone' => '030123456',
);

$source = file_get_contents($templatePdf);
assert_true(is_string($source) && $source !== '', 'Expected template PDF fixture content.');

$noApSource = preg_replace('/\s*\/AP << \/N \d+ 0 R >>/m', '', $source, -1, $replacements);
assert_true(is_string($noApSource) && $replacements >= 4, 'Expected to strip existing widget /AP entries from the template fixture.');

$tempPdf = tempnam(sys_get_temp_dir(), 'fpdm-noap-');
assert_true($tempPdf !== false, 'Unable to create temporary PDF fixture.');
if (file_put_contents($tempPdf, $noApSource) === false) {
    @unlink($tempPdf);
    fail('Unable to write temporary no-AP PDF fixture.');
}

try {
    $pdf = new $fpdmClass($tempPdf);
    $pdf->Load($fields, $isUTF8);
    $pdf->Merge();
    $output = $pdf->Output('S');
} catch (\Exception $e) {
    @unlink($tempPdf);
    fail('Generated appearance merge unexpectedly failed: [' . get_class($e) . '] ' . $e->getMessage());
}

@unlink($tempPdf);

assert_true(is_string($output) && strlen($output) > 0, 'Expected a non-empty generated-appearance PDF output.');
assert_true(strpos($output, '/NeedAppearances true') !== false, 'Expected /NeedAppearances true to be inserted into the AcroForm dictionary.');

foreach ($fields as $fieldName => $fieldValue) {
    $fieldObject = find_pdf_object_by_field_name($output, $fieldName);
    assert_true(is_array($fieldObject), 'Expected to find field object for ' . $fieldName . '.');

    $encodedFieldValue = encode_field_hex_value($fieldValue, $isUTF8);
    assert_true(
        strpos($fieldObject['body'], '/V <' . $encodedFieldValue . '>') !== false,
        'Expected field ' . $fieldName . ' to contain the updated /V value.'
    );

    assert_true(
        preg_match('/\/AP\s+<<\s*\/N\s+(\d+)\s+0\s+R\s*>>/', $fieldObject['body'], $appearanceMatch) === 1,
        'Expected field ' . $fieldName . ' to reference a generated normal appearance stream.'
    );

    $appearanceObject = find_pdf_object_by_id($output, intval($appearanceMatch[1]));
    assert_true(is_string($appearanceObject), 'Expected appearance object ' . $appearanceMatch[1] . ' for field ' . $fieldName . '.');

    $encodedAppearanceValue = encode_appearance_hex_value($fieldValue, $isUTF8);
    assert_true(
        strpos($appearanceObject, '<' . $encodedAppearanceValue . '> Tj') !== false,
        'Expected appearance object ' . $appearanceMatch[1] . ' to contain the rendered text for field ' . $fieldName . '.'
    );
    assert_true(
        strpos($appearanceObject, '<FEFF') === false,
        'Appearance object ' . $appearanceMatch[1] . ' for field ' . $fieldName . ' must not contain a UTF-16 BOM.'
    );
}

fwrite(STDOUT, "Generated text widget appearance regression test passed.\n");

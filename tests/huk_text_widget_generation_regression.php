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

function encode_pdf_hex_value($value) {
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
$hukPdf = __DIR__ . '/../Schadenformular HUK LW.cleaned.pdf';
$fields = array(
    'Versicherungsnehmer' => 'Max Mustermann',
    'Telefon' => '030123456',
    'Datum' => '17.03.2026',
    'Bemerkungen' => 'Test visible on iOS',
    'Ergebnis' => 'Probeausgabe',
);

try {
    $pdf = new $fpdmClass($hukPdf);
    $pdf->Load($fields, false);
    $pdf->Merge();
    $output = $pdf->Output('S');
} catch (\Exception $e) {
    fail('HUK merge unexpectedly failed: [' . get_class($e) . '] ' . $e->getMessage());
}

assert_true(is_string($output) && strlen($output) > 0, 'Expected a non-empty HUK PDF output.');
assert_true(strpos($output, '/NeedAppearances true') !== false, 'Expected /NeedAppearances true to be inserted into the AcroForm dictionary.');

foreach ($fields as $fieldName => $fieldValue) {
    $fieldObject = find_pdf_object_by_field_name($output, $fieldName);
    assert_true(is_array($fieldObject), 'Expected to find field object for ' . $fieldName . '.');

    $encodedValue = encode_pdf_hex_value($fieldValue);
    assert_true(
        strpos($fieldObject['body'], '/V <' . $encodedValue . '>') !== false,
        'Expected field ' . $fieldName . ' to contain the updated /V value.'
    );

    assert_true(
        preg_match('/\/AP\s+<<\s*\/N\s+(\d+)\s+0\s+R\s*>>/', $fieldObject['body'], $appearanceMatch) === 1,
        'Expected field ' . $fieldName . ' to reference a generated normal appearance stream.'
    );

    $appearanceObject = find_pdf_object_by_id($output, intval($appearanceMatch[1]));
    assert_true(is_string($appearanceObject), 'Expected appearance object ' . $appearanceMatch[1] . ' for field ' . $fieldName . '.');
    assert_true(
        strpos($appearanceObject, '<' . $encodedValue . '> Tj') !== false,
        'Expected appearance object ' . $appearanceMatch[1] . ' to contain the rendered text for field ' . $fieldName . '.'
    );
}

fwrite(STDOUT, "HUK text widget generation regression test passed.\n");

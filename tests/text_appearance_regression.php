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

$fpdmClass = class_exists('\\Magicplan\\Fpdm\\FPDM') ? '\\Magicplan\\Fpdm\\FPDM' : 'FPDM';
$templatePdf = __DIR__ . '/../src/template.pdf';
$value = 'Jörg';
$encodedValue = strtoupper(bin2hex("\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', $value)));

try {
    $pdf = new $fpdmClass($templatePdf);
    $pdf->Load(array('name' => $value), true);
    $pdf->Merge();
    $output = $pdf->Output('S');
} catch (\Exception $e) {
    fail('Merge unexpectedly failed: [' . get_class($e) . '] ' . $e->getMessage());
}

assert_true(is_string($output) && strlen($output) > 0, 'Expected a non-empty PDF output.');

$fieldPattern = '/(\d+)\s+0\s+obj\s*<<.*?\/T \(name\).*?\/V <' . preg_quote($encodedValue, '/') . '>.*?\/AP << \/N (\d+)\s+0\s+R >>/s';
assert_true(
    preg_match($fieldPattern, $output, $fieldMatch) === 1,
    'Expected the filled field object to contain both the UTF-8 /V value and an /AP /N reference.'
);

$appearanceObjectId = $fieldMatch[2];
$appearancePattern = '/' . preg_quote($appearanceObjectId, '/') . '\s+0\s+obj\s*<<.*?stream\s.*?<' . preg_quote($encodedValue, '/') . '>\s*Tj\s.*?endstream/s';
assert_true(
    preg_match($appearancePattern, $output) === 1,
    'Expected the widget normal appearance stream to be updated with the filled value.'
);

fwrite(STDOUT, "Text appearance regression test passed.\n");

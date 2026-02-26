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
$exceptionClass = class_exists('\\Magicplan\\Fpdm\\FPDMException') ? '\\Magicplan\\Fpdm\\FPDMException' : 'FPDMException';

$validPdf = __DIR__ . '/../src/template.pdf';
$fields = array(
    'name' => 'Smoke Name',
    'address' => 'Smoke Address',
    'city' => 'Smoke City',
    'phone' => '123-456',
);

try {
    $pdf = new $fpdmClass($validPdf);
    $pdf->Load($fields, false);
    $pdf->Merge();
    $output = $pdf->Output('S');
    assert_true(is_string($output) && strlen($output) > 0, 'Valid PDF should produce non-empty output.');
    assert_true(substr($output, 0, 4) === '%PDF', 'Valid PDF output should start with %PDF.');
} catch (\Exception $e) {
    fail('Valid PDF unexpectedly threw an exception: [' . get_class($e) . '] ' . $e->getMessage());
}

$corruptPdf = tempnam(sys_get_temp_dir(), 'fpdm-corrupt-');
if ($corruptPdf === false) {
    fail('Unable to create temporary corrupt PDF file.');
}

$corruptContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
if (file_put_contents($corruptPdf, $corruptContent) === false) {
    @unlink($corruptPdf);
    fail('Unable to write corrupt PDF content.');
}

$caught = false;
try {
    $pdf = new $fpdmClass($corruptPdf);
    $pdf->Load($fields, false);
    $pdf->Merge();
    fail('Corrupt PDF should have thrown an exception during merge.');
} catch (\Exception $e) {
    $caught = true;
    assert_true(is_a($e, $exceptionClass), 'Corrupt PDF should throw ' . $exceptionClass . ', got ' . get_class($e));
    assert_true(strpos($e->getMessage(), 'FPDF-Merge Error: ') === 0, 'Unexpected exception message prefix: ' . $e->getMessage());
}

@unlink($corruptPdf);

assert_true($caught, 'Expected exception was not caught for corrupt PDF.');

fwrite(STDOUT, "Smoke test passed.\n");

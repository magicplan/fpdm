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

function build_pdf_fixture(array $objects) {
    ksort($objects);

    $buffer = "%PDF-1.4\n";
    $offsets = array(0 => 0);
    foreach ($objects as $objectId => $objectBody) {
        $offsets[$objectId] = strlen($buffer);
        $buffer .= $objectId . " 0 obj\n" . $objectBody . "\nendobj\n";
    }

    $xrefOffset = strlen($buffer);
    $count = max(array_keys($objects));
    $buffer .= "xref\n0 " . ($count + 1) . "\n";
    $buffer .= "0000000000 65535 f \n";
    for ($objectId = 1; $objectId <= $count; $objectId++) {
        $offset = isset($offsets[$objectId]) ? $offsets[$objectId] : 0;
        $status = isset($offsets[$objectId]) ? 'n' : 'f';
        $generation = isset($offsets[$objectId]) ? '00000' : '65535';
        $buffer .= sprintf('%010d %s %s ', $offset, $generation, $status) . "\n";
    }

    $buffer .= "trailer\n";
    $buffer .= "<< /Size " . ($count + 1) . " /Root 1 0 R >>\n";
    $buffer .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

    return $buffer;
}

$fpdmClass = class_exists('\\Magicplan\\Fpdm\\FPDM') ? '\\Magicplan\\Fpdm\\FPDM' : 'FPDM';
$objects = array(
    1 => implode("\n", array(
        "<<",
        "/Type /Catalog",
        "/Pages 2 0 R",
        "/AcroForm 4 0 R",
        ">>",
    )),
    2 => implode("\n", array(
        "<<",
        "/Type /Pages",
        "/Count 1",
        "/Kids [ 3 0 R ]",
        ">>",
    )),
    3 => implode("\n", array(
        "<<",
        "/Type /Page",
        "/Parent 2 0 R",
        "/MediaBox [ 0 0 100 100 ]",
        "/Resources << /Font << /Helv 6 0 R /ZaDb 7 0 R >> >>",
        "/Annots [ 5 0 R 8 0 R ]",
        ">>",
    )),
    4 => "<< /DA (/Helv 0 Tf 0 g) /DR << /Font << /Helv 6 0 R /ZaDb 7 0 R >> >> /Fields [ 9 0 R 8 0 R ] >>",
    5 => implode("\n", array(
        "<<",
        "/AP << /D << /Yes 10 0 R /Off 11 0 R >> /N << /Yes 12 0 R >> >>",
        "/AS /Off",
        "/P 3 0 R",
        "/Parent 9 0 R",
        "/Rect [ 10 10 20 20 ]",
        "/Subtype /Widget",
        "/Type /Annot",
        ">>",
    )),
    6 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Name /Helv >>",
    7 => "<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats /Name /ZaDb >>",
    8 => implode("\n", array(
        "<<",
        "/DA (/Helv 10 Tf 0 g)",
        "/FT /Tx",
        "/P 3 0 R",
        "/Rect [ 30 30 80 40 ]",
        "/Subtype /Widget",
        "/T (name)",
        "/Type /Annot",
        ">>",
    )),
    9 => implode("\n", array(
        "<<",
        "/DA (/ZaDb 0 Tf 0 g)",
        "/FT /Btn",
        "/Kids [ 5 0 R ]",
        "/T (choice)",
        ">>",
    )),
    10 => "<< /Type /XObject /Subtype /Form /BBox [ 0 0 10 10 ] /Length 0 >>\nstream\n\nendstream",
    11 => "<< /Type /XObject /Subtype /Form /BBox [ 0 0 10 10 ] /Length 0 >>\nstream\n\nendstream",
    12 => "<< /Type /XObject /Subtype /Form /BBox [ 0 0 10 10 ] /Length 0 >>\nstream\n\nendstream",
);

$tempPdf = tempnam(sys_get_temp_dir(), 'fpdm-checkbox-');
assert_true($tempPdf !== false, 'Unable to create temporary checkbox PDF fixture.');
if (file_put_contents($tempPdf, build_pdf_fixture($objects)) === false) {
    @unlink($tempPdf);
    fail('Unable to write checkbox PDF fixture.');
}

try {
    $pdf = new $fpdmClass($tempPdf);
    $pdf->useCheckboxParser = true;
    $pdf->Load(array('name' => 'Smoke', 'choice' => true), false);
    $pdf->Merge();
    $output = $pdf->Output('S');
} catch (\Exception $e) {
    @unlink($tempPdf);
    fail('Checkbox regression merge unexpectedly failed: [' . get_class($e) . '] ' . $e->getMessage());
}

@unlink($tempPdf);

assert_true(strpos($output, '/NeedAppearances true') !== false, 'Expected /NeedAppearances true to be inserted.');
assert_true(strpos($output, '/Fields [ 8 0 R ]') !== false, 'Expected checkbox parent field to be removed from /Fields while keeping the text field.');
assert_true(strpos($output, '/Fields [ 9 0 R 8 0 R ]') === false, 'Checkbox parent field should not remain in /Fields after merge.');
assert_true(strpos($output, '/AS /Yes') !== false, 'Expected checkbox widget appearance state to be updated.');
assert_true(strpos($output, '/V /Yes') !== false, 'Expected checkbox parent value to be updated.');
assert_true(
    preg_match('/5\s+0\s+obj\s*<<.*?\/AP << \/D << \/Yes 10 0 R \/Off 11 0 R >> \/N << \/Yes 12 0 R >> >>.*?\/AS \/Yes.*?endobj/s', $output) === 1,
    'Expected the checkbox widget to keep its original button appearance dictionary instead of receiving a generated text appearance.'
);
assert_true(
    preg_match('/5\s+0\s+obj\s*<<.*?\/AP\s+<<\s*\/N\s+\d+\s+0\s+R\s*>>\s*\/N\s+<<\s*\/Yes\s+12\s+0\s+R.*?endobj/s', $output) !== 1,
    'Checkbox widgets must not receive a generated text /AP /N stream.'
);

fwrite(STDOUT, "Checkbox NeedAppearances regression test passed.\n");

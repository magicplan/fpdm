<?php

require_once __DIR__ . '/FPDM.php';

if (!class_exists('FPDM', false)) {
    class_alias(\Magicplan\Fpdm\FPDM::class, 'FPDM');
}

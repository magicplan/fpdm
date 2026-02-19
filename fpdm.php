<?php

/**
 * Entry point for legacy calls
 *
 * Devs not using composer autoload will have included this file directly.
 * Keeping it as a wrapper allows to retain compatibility with legacy projects
 * while allowing adjustments to the source to improve composer integration.
 */

define('FPDM_DIRECT', true);

require_once(__DIR__ . "/src/legacy_alias.php");

require_once(__DIR__ . "/src/filters/FilterASCIIHex.php");
require_once(__DIR__ . "/src/filters/FilterASCII85.php");
require_once(__DIR__ . "/src/filters/FilterFlate.php");
require_once(__DIR__ . "/src/filters/FilterLZW.php");
require_once(__DIR__ . "/src/filters/FilterStandard.php");

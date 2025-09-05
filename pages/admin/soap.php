<?php
// soap_test.php — minimal SOAP tester
include(__DIR__ . "/../../config.php");

// Simple helper to run and show results
function testCommand($cmd) {
    echo "<h3>Command: <code>{$cmd}</code></h3>";
    [$ok, $resp] = sendSoap($cmd);
    if ($ok) {
        echo "<pre style='color:green'>SUCCESS:\n" . htmlspecialchars($resp) . "</pre>";
    } else {
        echo "<pre style='color:red'>FAILED:\n" . htmlspecialchars($resp) . "</pre>";
    }
}

// Run some test commands
echo "<h1>SOAP Tester</h1>";
testCommand("server info");     // safe, just shows server info
testCommand("account list");    // shows accounts
testCommand("kick Rage"); // replace CHARNAME with one of your characters

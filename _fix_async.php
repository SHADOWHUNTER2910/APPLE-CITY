<?php
$c = file_get_contents('index.html');

// Fix missing async keywords
$fixes = [
    'function loadReceiptProductUnits(' => 'async function loadReceiptProductUnits(',
    'function viewReceipt('             => 'async function viewReceipt(',
    'function viewAndPrintReceipt('     => 'async function viewAndPrintReceipt(',
    'function printReceipt('            => 'async function printReceipt(',
];

foreach ($fixes as $old => $new) {
    if (strpos($c, $new) !== false) {
        echo "Already async: $new\n";
        continue;
    }
    if (strpos($c, $old) !== false) {
        $c = str_replace($old, $new, $c);
        echo "Fixed: $old -> $new\n";
    } else {
        echo "NOT FOUND: $old\n";
    }
}

// Also fix duplicate async on loadReceiptHistory
$c = str_replace('async async function loadReceiptHistory', 'async function loadReceiptHistory', $c);

file_put_contents('index.html', $c);
echo "Done. Size: " . filesize('index.html') . "\n";

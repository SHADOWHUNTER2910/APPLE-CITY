<?php
$c = file_get_contents('index.html');
$checks = ['section-settings','section-predictions','form-company-settings','pred-filter','tbl-predictions','company-preview'];
foreach($checks as $k) {
    echo (strpos($c, 'id="'.$k.'"') !== false ? 'OK   ' : 'MISS ') . $k . "\n";
}

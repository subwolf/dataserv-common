#!/usr/bin/php
<?php

/*
    Stats Poller for Driveshare
    Written by Robin Beckett
    Source code on Github
*/

$urls = array(
    'v2.0.x' => "http://status.driveshare.org:5000/api/online/json",
    'v1.3  ' => "http://status.driveshare.org/api/online/json"
);

function GetStats($name = "", $url = "") {
    $data = file_get_contents($url);
    if ($data === false)
        return array('error' => "Failed to get data.");
    $parsed = json_decode($data, TRUE);
    if ($parsed === NULL)
        return array('error' => "Failed to parse JSON.");
    
    $return['farmers']      = 0;
    $return['total_height'] = 0;
    $return['height']       = array();
    $return['bad_farmers']  = 0;
    $return['bad_height']   = array();
    
    foreach ($parsed['farmers'] as $farmer) {
        if ($farmer['height'] < (8192 * 25)) {
            $return['height'][$farmer['btc_addr']] = $farmer['height'];
            $return['total_height'] += $farmer['height'];
            $return['farmers']++;
        } else {
            $return['bad_farmers']++;
            $return['bad_height'][$farmer['btc_addr']] = $farmer['height'];
        }
    }
    
    return $return;
}

$data = array();

$final_height = 0;

foreach ($urls as $version => $url) {
    $data[$version] = GetStats($version, $url);
    if (isset($data[$version]["error"])) 
        die("Error parsing {$url}: {$data[$version]['error']}\n\n");
    $final_height += $data[$version]['total_height'];
    $this_served = number_format(($data[$version]['total_height'] / 8192), 3);
    $farmers     = $data[$version]['farmers'];
    $average     = number_format(((array_sum($data[$version]['height']) / count($data[$version]['height'])) / 8192), 3);
   
    echo "On {$version}: {$farmers} farmers sharing {$this_served} TiB data (average {$average} TiB)\n";
    if ($data[$version]['bad_farmers'] > 0) {
        echo "Bad Farmers:\n";
        foreach ($data[$version]['bad_height'] as $addr => $height) {
            echo "- {$addr} sharing " . number_format(($height / 8192), 3) . " TiB (" . trim($version) . ")\n";
        }
    }
}

$final_height = number_format(($final_height / 8192), 3);

echo "Total shared: {$final_height} TiB\n\n";

?>

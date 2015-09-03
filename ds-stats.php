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
    $return['height'] = array();
    
    foreach ($parsed['farmers'] as $farmer) {
        $return['height'][$farmer['btc_addr']] = $farmer['height'];
        $return['total_height'] += $farmer['height'];
        $return['farmers']++;
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
   
    echo "On {$version}: {$farmers} farmers sharing {$this_served} TiB data\n";
}

$final_height = number_format(($final_height / 8192), 3);

echo "Total shared: {$final_height} TiB\n\n";

?>

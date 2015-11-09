#!/usr/bin/php
<?php

/*
    Stats Poller for Driveshare
    Written by Robin Beckett
    Source code on Github
*/

/* Array of urls to fetch */
$urls = array(
    'v2.1.4' => "http://status.driveshare.org/api/online/json"
);

$jsonFile = 'ds-stats.json';

/* Get whitelist if needed */
$sFile  = file_get_contents($jsonFile);
if (strlen($sFile) == 0)
{
    $sData = array();
    $rows   = array_map('str_getcsv', file('storj_crowdfunding_by_amount.txt'));
    $header = array_shift($rows);
    $csv    = array();
    foreach ($rows as $row) {
        $csv[] = array_combine($header, $row);
    }

    $whitelist = array();
    foreach ($csv as $row) {
        $sData['whitelist'][] = $row['sourceAddress'];
    }    
}
else
    $sData = json_decode($sFile, True);

$sData['balUrl'] = "http://api.blockscan.com/api2?module=address&action=balance&asset=SJCX&btc_address=";            

function GetStats($name = "", $url = "") {
    global $sData;
    
    if (!isset($sData['balance'])) $sData['balance'] = array();
    
    $data = file_get_contents($url);
    if ($data === false)
        return array('error' => "Failed to get data.");
    $parsed = json_decode($data, TRUE);
    if ($parsed === NULL)
        return array('error' => "Failed to parse JSON.");

    foreach (array('wl', 'non-wl') as $val) {
        $return[$val]['farmers']        = 0;
        $return[$val]['total_height']   = 0;
        $return[$val]['height']         = array();
        $return[$val]['duplicates']     = 0;
        $return[$val]['under_10k']      = 0;
        $return[$val]['over_10k']       = 0;
        $return[$val]['sjcx_over_10k']  = 0;
        $return[$val]['sjcx_under_10k'] = 0;
        $return[$val]['total_sjcx']     = 0;
    }
    
    // Are they whitelisted?
    foreach ($parsed['farmers'] as $farmer) {
        if (in_array($farmer['payout_addr'], $sData['whitelist']))
            $t = 'wl';
        else
            $t = 'non-wl';
            
        $return[$t]['height'][$farmer['btc_addr']] = $farmer['height'];
        $return[$t]['total_height'] += $farmer['height'];
        $return[$t]['farmers']++;
        if (in_array($farmer['payout_addr'], array_keys($return[$t]['height'])))
            $return[$t]['duplicates']++;
            
        // Do we have balance information?
        if (!isset($sData['balance'][$farmer['btc_addr']]))
            $gb = True;
        elseif ($sData['balance'][$farmer['btc_addr']]['last'] > (time() + (60 * 60 * 24)))
            $gb = True;
        else
            $gb = False;
            
        if ($gb == True) {
            $balance = (array) json_decode(file_get_contents($sData['balUrl'] . $farmer['payout_addr']));
            var_dump($balance);
            if ($balance['status'] == "success") {
                $bal = $balance['data'][0]->balance;
                if ($bal >= 10000) {
                    $return[$t]['over_10k']++;
                    $return[$t]['sjcx_over_10k'] += $bal;
                } else {
                    $return[$t]['under_10k']++;
                    $return[$t]['sjcx_under_10k'] += $bal;
                }
                
                $return[$t]['total_sjcx'] += $bal;
                $sData['balance'][$farmer['btc_addr']]['balance'] = $bal;
            } else {
                $sData['balance'][$farmer['btc_addr']]['balance'] = 0;            
            }

            $sData['balance'][$farmer['btc_addr']]['last'] = time();
            sleep(0.5);
        } else {
            $bal = $sData['balance'][$farmer['btc_addr']]['balance'];
            if ($bal >= 10000) {
                $return[$t]['over_10k']++;
                $return[$t]['sjcx_over_10k'] += $bal;
            } else {
                $return[$t]['under_10k']++;
                $return[$t]['sjcx_under_10k'] += $bal;
            }
            $return[$t]['total_sjcx'] += $bal;
        }
        
        // break; // Out early
    }
    
    return $return;
}

$data         = array();
$final_height = 0;
$final_sjcx   = 0;

foreach ($urls as $version => $url) {
    $wl = array('wl' => "Whitelisted", 'non-wl' => "Non-whitelisted");
    $data[$version] = GetStats($version, $url);
    if (isset($data[$version]["error"])) 
        die("Error parsing {$url}: {$data[$version]['error']}\n\n");
    
    foreach ($wl as $key => $val) {
        $final_height  += $data[$version][$key]['total_height'];
        $final_sjcx    += $data[$version][$key]['total_sjcx'];
        $this_served    = number_format(($data[$version][$key]['total_height'] / 8192), 3);
        $farmers        = $data[$version][$key]['farmers'];
        $average        = number_format(((array_sum($data[$version][$key]['height']) /
                          count($data[$version][$key]['height'])) / 8192), 3);
        $duplicates     = $data[$version][$key]['duplicates'];
        $total_sjcx     = number_format($data[$version][$key]['total_sjcx'], 0);
       
        echo "On {$version} ({$val}): {$farmers} farmers sharing {$this_served} TiB data, " .
             "averaging {$average} TiB, total {$total_sjcx} SJCX ({$duplicates} duplicates)\n";
    }
}

$final_height = number_format(($final_height / 8192), 3);
$final_sjcx   = number_format($final_sjcx, 0);


echo "Total shared: {$final_height} TiB with {$final_sjcx} SJCX\n\n";

$sData = json_encode($sData);
$sFile = fopen($jsonFile, "w");
fwrite($sFile, $sData);
fclose($sFile);

?>

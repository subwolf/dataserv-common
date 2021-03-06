#!/usr/bin/php
<?php

/*
    Stats Poller for Driveshare
    Written by Robin Beckett
    Source code on Github
*/

/* Array of urls to fetch */
$urls = array(
    'v2.1.x' => "http://status.driveshare.org/api/online/json"
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
        $return[$val]['farmers']          = 0;
        $return[$val]['total_height']     = 0;
        $return[$val]['height'][$val]     = array();
        $return[$val]['duplicates']       = array();
        $return[$val]['duplicate_count']  = 0;
        $return[$val]['under_10k']        = 0;
        $return[$val]['over_10k']         = 0;
        $return[$val]['sjcx_over_10k']    = 0;
        $return[$val]['sjcx_under_10k']   = 0;
        $return[$val]['total_sjcx']       = 0;
    }
    
    // Are they whitelisted?
    foreach ($parsed['farmers'] as $farmer) {
        if (in_array($farmer['payout_addr'], $sData['whitelist']))
            $t = 'wl';
        else
            $t = 'non-wl';
            
        $return[$t]['total_height'] += $farmer['height'];
        $return[$t]['farmers']++;
        if (in_array($farmer['payout_addr'], array_keys($return[$t]['height'])) &&
            !in_array($farmer['btc_addr'], array_keys($return[$t]['height'])))
        {
            if (!isset($return[$t]['duplicates'][$farmer['payout_addr']]))
                $return[$t]['duplicates'][$farmer['payout_addr']] = 2;
            else
                $return[$t]['duplicates'][$farmer['payout_addr']]+= 2;
            
            if (!isset($return[$t]['duplicate_height'][$farmer['payout_addr']]))
                $return[$t]['duplicate_height'][$farmer['payout_addr']] = $return[$t]['height'][$farmer['payout_addr']];
            $return[$t]['duplicate_height'][$farmer['payout_addr']] += $farmer['height'];

            $return[$t]['duplicate_count']+= 2;
        }

        $return[$t]['height'][$farmer['payout_addr']] = $farmer['height'];
            
        // Do we have balance information?
        if (!isset($sData['balance'][$farmer['btc_addr']]))
            $gb = True;
        elseif ($sData['balance'][$farmer['btc_addr']]['last'] > (time() + (60 * 60 * 24)))
            $gb = True;
        else
            $gb = False;
            
        if ($gb == True) {
            // echo "Getting balance for {$farmer['payout_addr']} ...\n";
            echo ".";
            flush();
            $balance = (array) json_decode(file_get_contents($sData['balUrl'] . $farmer['payout_addr']));
            if ($balance['status'] == "success") {
                $bal = $balance['data'][0]->balance;
                $return[$t]['total_sjcx'] += $bal;
                $sData['balance'][$farmer['btc_addr']]['balance'] = $bal;
            } else {
                $sData['balance'][$farmer['btc_addr']]['balance'] = 0;            
            }
            
            $sData['balance'][$farmer['btc_addr']]['last'] = time();
            sleep(0.5);
        } else {
            $bal = $sData['balance'][$farmer['btc_addr']]['balance'];
            $return[$t]['total_sjcx'] += $bal;
        }
      
        if ($bal >= 10000) {
            $return[$t]['over_10k']++;
            $return[$t]['sjcx_over_10k'] += $bal;
        } else {
            $return[$t]['under_10k']++;
            $return[$t]['sjcx_under_10k'] += $bal;
        }
    }
    
    return $return;
}

$data           = array();
$final_height   = 0;
$final_sjcx     = 0;
$final_peers    = 0;
$limited_height = 0;

echo "Updating balances...";
$wl = array('wl' => "Crowdsale", 'non-wl' => "Other Testers");

foreach ($urls as $version => $url) {
    $data[$version] = GetStats($version, $url);
    if (isset($data[$version]["error"])) 
        die("Error parsing {$url}: {$data[$version]['error']}\n\n");
}

echo " done.\n";

date_default_timezone_set('UTC');

echo "\nData correct as of " . date(DATE_RFC822) . "\n";

$duplicate_list = array();

foreach ($urls as $version => $url) {
    foreach ($wl as $key => $val) {
        $thisOne        = $data[$version][$key];
        $final_height  += $thisOne['total_height'];
        $final_sjcx    += $thisOne['total_sjcx'];
        $this_served    = number_format(($thisOne['total_height'] / 8192), 3);
        $farmers        = $thisOne['farmers'];
        $average        = number_format(((array_sum($thisOne['height']) /
                          count($thisOne['height'])) / 8192), 3);
        $duplicates     = $thisOne['duplicate_count'];
        $total_sjcx     = number_format($thisOne['total_sjcx'], 0);
        $final_peers   += $thisOne['farmers'];
       
        echo "On {$version} ({$val}): {$farmers} farmers sharing {$this_served} TiB data, " .
             "averaging {$average} TiB, total {$total_sjcx} SJCX ({$duplicates} duplicates)\n";
             
        if ($duplicates > 0) {
            $duplicate_list[$key][$url] = $thisOne['duplicates'];
            $duplicate_height[$key][$url] = $data[$version][$key]['duplicate_height'];
            foreach ($duplicate_height[$key][$url] as $addr => $height) {
                if ($height > (8192 * 25))
                    $limited_height += (8192 * 25);
                else
                    $limited_height += $height;
            }
        }
    }
}

$final_height = number_format(($final_height / 8192 / 1024), 5);
$final_sjcx   = number_format($final_sjcx, 0);
$final_peers  = number_format($final_peers, 0);

echo "\nTotal shared: {$final_height} PiB by {$final_peers} peers holding {$final_sjcx} SJCX\n";

if (count($duplicate_list) > 0)
{
    echo "Total shared when addresses limited to 25 TiB: " . number_format(($limited_height / 8192 / 1024), 3) . " PiB\n\n";
    
    echo "Duplicate addresses: \n";
    foreach ($urls as $version => $url) {
        foreach ($wl as $key => $val) {
            foreach ($duplicate_list[$key][$url] as $addr => $count) {
                $dupe_shared = number_format(($duplicate_height[$key][$url][$addr] / 8192), 3);
                echo "- {$addr} with {$count} entries ({$val}) sharing {$dupe_shared} TiB\n";
            }
        }
    }
}

$sData = json_encode($sData);
$sFile = fopen($jsonFile, "w");
fwrite($sFile, $sData);
fclose($sFile);

?>

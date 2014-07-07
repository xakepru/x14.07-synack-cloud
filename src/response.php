<?php

/**
 * User: Vasiliy Shvakin (orbisnull) zen4dev@gmail.com
 */
class SysInfo
{
    function getLoadAverage()
    {
        $la = sys_getloadavg();
        return $la[0];
    }

    function getSystemMemInfo()
    {
        $data = file("/proc/meminfo", FILE_IGNORE_NEW_LINES);
        $memInfo = array();
        foreach ($data as $line) {
            list($key, $val) = explode(":", $line);
            $tmpVal = explode(" ", trim($val));
            $memInfo[$key] = (integer)$tmpVal[0];
        }
        return $memInfo;
    }

    function getUsedActiveMemPercent()
    {
        $memInfo = $this->getSystemMemInfo();
        $usedMem = $memInfo["MemTotal"] - $memInfo["MemFree"];
        $usedActive = $usedMem - $memInfo["Cached"];
        $freePercent = ($usedActive / $memInfo["MemTotal"] ) * 100;
        return round($freePercent);
    }
}

$sysInfo = new SysInfo();

header('Content-Type: application/json');
echo json_encode(
    array("LA"             => $sysInfo->getLoadAverage(),
          "memUsedActivePercent" => $sysInfo->getUsedActiveMemPercent(),
    ), JSON_PRETTY_PRINT
);
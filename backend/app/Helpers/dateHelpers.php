<?php


if(!function_exists('convertExcelDateTo')){
    function convertExcelDateTo($value,$format = 'Y-m-d')
    {
        $excelDateValue = $value - 25569; // convert to unix timestamp (seconds since 1970-01-01 00:00:00 UTC)
        $unixTimestamp = round($excelDateValue * 86400); // convert from days to seconds
        $date = date($format, $unixTimestamp); // format date as 'yyyy-mm-dd'
        return $date;
    }
}

if(!function_exists('getWorkingDays')){
    function getWorkingDays($startDate, $endDate,$holidays = [])
    {
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        $workingDays = 0;

        while ($start->lte($end)) {
            if (!$start->isWeekend() && !in_array($start->format('Y-m-d'), $holidays)) {
                $workingDays++;
            }
            $start->addDay();
        }

        return $workingDays;
    }
}

if(!function_exists('getDateFormat')){
    function getDateFormat($dateString)
    {
        $formats = [
            'Y-m-d', 'd-m-Y', 'm/d/Y', 'Y/m/d', 'd/m/Y',
            'Y-m-d H:i:s', 'd-m-Y H:i:s', 'm/d/Y H:i:s'
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date && $date->format($format) === $dateString) {
                return $format;
            }
        }

        return null;
    }
}


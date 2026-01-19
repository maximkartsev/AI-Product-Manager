<?php

if (!function_exists('mb_ucfirst') && function_exists('mb_substr')) {
    function mb_ucfirst($string) {
        $string = mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);
        return $string;
    }
}


if(!function_exists('objectToArrayRecursive')) {
    function objectToArrayRecursive($data) {
        if (is_object($data)) {
            // Convert object to array of its public properties
            $data = get_object_vars($data);
        }

        if (is_array($data)) {
            // Recursively apply to each element
            return array_map('objectToArrayRecursive', $data);
        }

        // Return scalar values as-is
        return $data;
    }

}

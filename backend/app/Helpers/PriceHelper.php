<?php

namespace App\Helpers;

class PriceHelper
{
    public static function convertToTextUkrainianPrice($num)
    {
        $priceParts = explode(".", $num); // Split the price into whole and decimal parts
        $wholePart = intval($priceParts[0]); // Get the whole part
        $decimalPart = isset($priceParts[1]) ? intval($priceParts[1]) : 0; // Get the decimal part (default to 0 if not set)

        // Convert the whole part to words
        $words = static::numberToTextUa($wholePart)." гривень";


        // Convert the decimal part to words
        if ($decimalPart > 0) {
            $decimalPart = static::numberToTextUa($decimalPart);
            $words .= " " .$decimalPart . " копійок";
        }

        return $words;
    }

    public static function numberToTextUa($num) {
        $ones = array(
            0 => 'нуль',
            1 => 'один',
            2 => 'два',
            3 => 'три',
            4 => 'чотири',
            5 => 'п\'ять',
            6 => 'шість',
            7 => 'сім',
            8 => 'вісім',
            9 => 'дев\'ять'
        );

        $tens = array(
            10 => 'десять',
            11 => 'одинадцять',
            12 => 'дванадцять',
            13 => 'тринадцять',
            14 => 'чотирнадцять',
            15 => 'п\'ятнадцять',
            16 => 'шістнадцять',
            17 => 'сімнадцять',
            18 => 'вісімнадцять',
            19 => 'дев\'ятнадцять',
            20 => 'двадцять',
            30 => 'тридцять',
            40 => 'сорок',
            50 => 'п\'ятдесят',
            60 => 'шістдесят',
            70 => 'сімдесят',
            80 => 'вісімдесят',
            90 => 'дев\'яносто'
        );

        $hundreds = array(
            100 => 'сто',
            200 => 'двісті',
            300 => 'триста',
            400 => 'чотириста',
            500 => 'п\'ятсот',
            600 => 'шістсот',
            700 => 'сімсот',
            800 => 'вісімсот',
            900 => 'дев\'ятсот'
        );

        if ($num == 0) {
            return $ones[0];
        }

        if ($num < 0) {
            return 'мінус ' . static::numberToTextUa(abs($num));
        }

        $text = '';

        if ($num >= 1000 && $num < 1000000) {
            $text .= static::numberToTextUa(floor($num / 1000)) . ' тисяч ';
            $num %= 1000;
        }

        if ($num >= 1000000 && $num < 1000000000) {
            $text .= static::numberToTextUa(floor($num / 1000000)) . ' мільйонів ';
            $num %= 1000000;
        }

        if ($num >= 1000000000 && $num < 1000000000000) {
            $text .= static::numberToTextUa(floor($num / 1000000000)) . ' мільярдів ';
            $num %= 1000000000;
        }

        if ($num >= 100 && $num <= 999) {
            $text .= $hundreds[floor($num / 100) * 100] . ' ';
            $num %= 100;
        }

        if ($num >= 20 && $num <= 99) {
            $text .= $tens[floor($num / 10) * 10] . ' ';
            $num %= 10;
        }
        if ($num >= 10 && $num <= 19) {
            $text .= $tens[$num] . ' ';
            return $text;
        }

        if ($num >= 1 && $num <= 9) {
            $text .= $ones[$num] . ' ';
            return $text;
        }

        return $text;

    }

    /**
     * Convert number to Ukrainian words
     *
     * @param float $number
     * @return string
     */
    static public function numberToUkrainianWords(float $number): string
    {
        $whole = (int)floor($number);
        $kopecks = (int)round(($number - $whole) * 100);

        $ones = ['', 'один', 'два', 'три', 'чотири', 'п\'ять', 'шість', 'сім', 'вісім', 'дев\'ять'];
        $teens = ['десять', 'одинадцять', 'дванадцять', 'тринадцять', 'чотирнадцять', 'п\'ятнадцять', 'шістнадцять', 'сімнадцять', 'вісімнадцять', 'дев\'ятнадцять'];
        $tens = ['', '', 'двадцять', 'тридцять', 'сорок', 'п\'ятдесят', 'шістдесят', 'сімдесят', 'вісімдесят', 'дев\'яносто'];
        $hundreds = ['', 'сто', 'двісті', 'триста', 'чотириста', 'п\'ятсот', 'шістсот', 'сімсот', 'вісімсот', 'дев\'ятсот'];

        $convertGroup = function($num) use ($ones, $teens, $tens, $hundreds) {
            if ($num == 0) return '';

            $result = '';
            $h = (int)($num / 100);
            $t = (int)(($num % 100) / 10);
            $o = $num % 10;

            if ($h > 0) {
                $result .= $hundreds[$h] . ' ';
            }

            if ($t == 1) {
                $result .= $teens[$o] . ' ';
            } else {
                if ($t > 1) {
                    $result .= $tens[$t] . ' ';
                }
                if ($o > 0) {
                    $result .= $ones[$o] . ' ';
                }
            }

            return trim($result);
        };

        $result = '';

        if ($whole == 0) {
            $result = 'нуль';
        } else {
            $millions = (int)($whole / 1000000);
            $thousands = (int)(($whole % 1000000) / 1000);
            $rest = $whole % 1000;

            if ($millions > 0) {
                $millionsText = $convertGroup($millions);
                $result .= $millionsText . ' ';
                $lastDigit = $millions % 10;
                $lastTwoDigits = $millions % 100;
                if ($lastTwoDigits >= 11 && $lastTwoDigits <= 19) {
                    $result .= 'мільйонів ';
                } elseif ($lastDigit == 1) {
                    $result .= 'мільйон ';
                } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
                    $result .= 'мільйони ';
                } else {
                    $result .= 'мільйонів ';
                }
            }

            if ($thousands > 0) {
                $thousandsText = $convertGroup($thousands);
                $result .= $thousandsText . ' ';
                $lastDigit = $thousands % 10;
                $lastTwoDigits = $thousands % 100;
                if ($lastTwoDigits >= 11 && $lastTwoDigits <= 19) {
                    $result .= 'тисяч ';
                } elseif ($lastDigit == 1) {
                    $result .= 'тисяча ';
                } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
                    $result .= 'тисячі ';
                } else {
                    $result .= 'тисяч ';
                }
            }

            if ($rest > 0) {
                $result .= $convertGroup($rest) . ' ';
            }
        }

        // Add currency word
        $lastDigit = $whole % 10;
        $lastTwoDigits = $whole % 100;

        if ($lastTwoDigits >= 11 && $lastTwoDigits <= 19) {
            $currencyWord = 'гривень';
        } elseif ($lastDigit == 1) {
            $currencyWord = 'гривня';
        } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
            $currencyWord = 'гривні';
        } else {
            $currencyWord = 'гривень';
        }

        // Kopeck word
        $kopeckLastDigit = $kopecks % 10;
        $kopeckLastTwoDigits = $kopecks % 100;
        if ($kopeckLastTwoDigits >= 11 && $kopeckLastTwoDigits <= 19) {
            $kopeckWord = 'копійок';
        } elseif ($kopeckLastDigit == 1) {
            $kopeckWord = 'копійка';
        } elseif ($kopeckLastDigit >= 2 && $kopeckLastDigit <= 4) {
            $kopeckWord = 'копійки';
        } else {
            $kopeckWord = 'копійок';
        }

        $result .= $currencyWord . ' ' . str_pad((string)$kopecks, 2, '0', STR_PAD_LEFT) . ' ' . $kopeckWord;

        return trim($result);
    }

}

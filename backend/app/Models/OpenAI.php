<?php

namespace App\Models;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OpenAI
{
    static public function extractData($text)
    {
        if (preg_match('/```json\s*(\{[\s\S]*?\})\s*```/', $text, $matches)) {
            $jsonString = $matches[1];
            return static::normalizeJSON($jsonString);
        } elseif (preg_match('/```\s*(\{[\s\S]*?\})\s*```/', $text, $matches)) {
            $jsonString = $matches[1];
            return static::normalizeJSON($jsonString);
        } elseif (preg_match('/```(\{[\s\S]*?\})```/', $text, $matches)) {
            $jsonString = $matches[1];
            return static::normalizeJSON($jsonString);
        } elseif (strpos($text, "{") !== false && strrpos($text, "}") !== false) {
            $start = strpos($text, "{");
            $end = strrpos($text, "}") + 1;
            $jsonString = substr($text, $start, $end - $start);
            return static::normalizeJSON($jsonString);
        } else {
            return null;
        }
    }

    static public function normalizeJSON($jsonString)
    {
        if (substr($jsonString, -3) === ",\n}") {
            $jsonString = substr($jsonString, 0, strlen($jsonString) - 3);
            $jsonString .= "\n}";
        }

        $jsonString = preg_replace('/[[:cntrl:]]/', '', $jsonString);
        $obj = json_decode($jsonString);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $obj;
        } else {
            return null;
        }
    }

    static public function askChatGPTCached($question, $extract = true,$ttl = 60 * 24)
    {
        $key = "openai_" . md5($question);
        $obj = Cache::get($key);

        if ($obj) {
            return $obj;
        }

        $obj = static::askChatGPT($question, $extract);

        if ($obj) {
            Cache::put($key, $obj, $ttl);
        }

        return $obj;
    }

    static public function askChatGPT($question, $extract = true)
    {
        // Define the OpenAI API endpoint and your API key
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $apiKey = env('OPEN_API_KEY');

        // Send a request to OpenAI API
        $response = Http::timeout(1000)->withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $question
                ]
            ],
            'temperature' => 0.7,
        ]);

        // Check for a successful response
        if ($response->successful()) {
            // Parse the response
            $data = $response->json();
            $content = $data['choices'][0]['message']['content'];

            // Display or save the updated product information

            if (!$extract) {
                return $content;
            }

            $obj = static::extractData($content);

            $chatId = $response->json()['id'];

            if (!$obj) {
                return null;
            }

            return $obj;
        } else {
            return null;
        }
    }

    public function normalizeObject($object)
    {
        $object = (array)$object;

        foreach ($object as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_object($v)) {
                        $v = (array)$v;
                    }

                    if (is_array($v)) {
                        $v = implode(" ", $v);
                    }
                    $value[$k] = $v;
                }

                $value = implode(" ", $value);
            }

            $object[$key] = $value;
        }

        return $object;
    }
}

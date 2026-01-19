<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Routing\Route;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_example()
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_api_list_enpoints()
    {
        // read all endpoints from api routes
        $apiRoutes = \Illuminate\Support\Facades\Route::getRoutes()->getRoutesByMethod()['GET'];

        // filter only index endpoints
        $apiIndexRoutes = array_filter($apiRoutes, function ($route) {
            return str_contains($route->getName(), 'index');
        });


        $loginEndpoint = '/api/login';

        $email = env('TEST_LOGIN_EMAIL', 'test@test.com');
        $password = env('TEST_LOGIN_PASSWORD', '123456');

        $loginResponse = $this->post($loginEndpoint, [
            'email' => $email,
            'password' => $password,
        ]);

        // assert the response status is 200
        $loginResponse->assertStatus(200);

        // assert the response has a token

        $loginResponse->assertJsonStructure([
            'data' => ['token'],
        ]);

        // get the token from the response
        $token = $loginResponse->json('data.token');

        $failedUrls = [];
        $succeedUrls = [];


        foreach ($apiIndexRoutes as $route) {

            /** @var Route $route */

            $requestHeaders = [
                'Authorization' => 'Bearer ' . $token,
            ];

            // print the route name and uri
            echo "\t Testing route: \t" . $route->getName() ."\n";

            echo "\t index without parameters: \t".$route->uri ."\t";


            // if the route has parameters in uri extract them and replace them with 1

            $parameterNames = $route->parameterNames();

            // Build parameter values array
            $parameters = [];
            foreach ($parameterNames as $paramName) {
                $parameters[$paramName] = 1; // You can customize value based on param name if needed
            }

            try {
                $uri = url($route->uri());
                if (!empty($parameters)) {
                    $uri = route($route->getName(), $parameters, false); // false: don't make full URL
                }

                echo "\n\t Testing URL: \t".$uri."\t";

                $response = $this->withHeaders($requestHeaders)
                    ->get($uri);

                $response->assertStatus(200);

                $succeedUrls[] = $uri;

                echo "\t \033[32m✓\033[0m \n"; // green check
            } catch (\Exception $e) {
                $failedUrls[] = $uri;
                echo "\t \033[31m✗\033[0m \t\n Exception: ".$e->getMessage()."\n";
            }

            // test with search parameter = 1
            echo "\t Testing route with search parameter: \t" . $route->getName() ."\n";

            echo "\t index with search parameter: \t".$route->uri ."\t";

            try {
                $uri = url($route->uri());

                $parameters['search'] = 1; // Add search parameter

                if (!empty($parameters)) {
                    $uri = route($route->getName(), $parameters, false); // false: don't make full URL
                }

                echo "\n\t Testing URL: \t".$uri."\t";

                $response = $this->withHeaders($requestHeaders)
                    ->get($uri);

                $response->assertStatus(200);

                $succeedUrls[] = $uri;

                echo "\t \033[32m✓\033[0m \n"; // green check
            } catch (\Exception $e) {
                $failedUrls[] = $uri;
                echo "\t \033[31m✗\033[0m \t\n Exception: ".$e->getMessage()."\n";
            }
        }

        if (!empty($failedUrls)) {
            echo "\n\n Failed routes: \n";
            foreach ($failedUrls as $failedUrl) {
                echo "\t - " . $failedUrl . "\n";
            }

            // break the test

            $message = count($failedUrls)." of ".(count($failedUrls)+count($succeedUrls))." urls failed.\n";
            $message.= count($succeedUrls)." of ".(count($failedUrls)+count($succeedUrls))." urls succeed.\n";
            $this->fail($message);
        } else {
            echo "\n\n ".count($succeedUrls)." url passed successfully.\n";
        }
    }
}

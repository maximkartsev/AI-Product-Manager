<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Routing\Route;
use Tests\TestCase;

class ApiTest extends TestCase
{
    protected static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$prepared) {
            // Ensure tenant pool DBs exist for pooled tenancy tests.
            try {
                DB::connection('central')->statement('CREATE DATABASE IF NOT EXISTS tenant_pool_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
                DB::connection('central')->statement('CREATE DATABASE IF NOT EXISTS tenant_pool_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
            } catch (\Throwable $e) {
                // ignore (may not be supported in some test environments)
            }

            Artisan::call('tenancy:pools-migrate');
            static::$prepared = true;
        }
    }

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


        $registerEndpoint = '/api/register';

        $email = 'test+' . uniqid() . '@test.com';
        $password = '12345678';

        $registerResponse = $this->post($registerEndpoint, [
            'name' => 'Test User',
            'email' => $email,
            'password' => $password,
            'c_password' => $password,
        ]);

        // assert the response status is 200
        $registerResponse->assertStatus(200);

        // assert the response has a token

        $registerResponse->assertJsonStructure([
            'data' => ['token', 'tenant' => ['domain']],
        ]);

        // get the token from the response
        $token = $registerResponse->json('data.token');
        $tenantDomain = $registerResponse->json('data.tenant.domain');
        $this->assertNotEmpty($tenantDomain);

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
                $uri = '/' . ltrim($route->uri(), '/');
                if (!empty($parameters)) {
                    $uri = route($route->getName(), $parameters, false); // false: don't make full URL
                }
                $uri = '/' . ltrim($uri, '/');

                echo "\n\t Testing URL: \t".$uri."\t";

                $response = $this->withHeaders($requestHeaders)
                    ->get("http://{$tenantDomain}{$uri}");

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
                $uri = '/' . ltrim($route->uri(), '/');

                $parameters['search'] = 1; // Add search parameter

                if (!empty($parameters)) {
                    $uri = route($route->getName(), $parameters, false); // false: don't make full URL
                }
                $uri = '/' . ltrim($uri, '/');

                echo "\n\t Testing URL: \t".$uri."\t";

                $response = $this->withHeaders($requestHeaders)
                    ->get("http://{$tenantDomain}{$uri}");

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

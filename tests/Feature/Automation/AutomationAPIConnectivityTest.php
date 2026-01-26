<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Simple API connectivity test
 */
class AutomationAPIConnectivityTest extends TestCase
{
    public function test_openrouter_api_connectivity(): void
    {
        $apiKey = config('openrouter.api_key');
        $model = config('openrouter.model');
        $baseUrl = config('openrouter.base_url');

        $this->assertNotEmpty($apiKey, 'API key should be configured');
        $this->assertNotEmpty($model, 'Model should be configured');

        echo "\n=== API CONNECTIVITY TEST ===\n";
        echo "API Key: " . substr($apiKey, 0, 15) . "...\n";
        echo "Model: {$model}\n";
        echo "Base URL: {$baseUrl}\n";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->timeout(30)->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Say "Hello" if you can read this message.',
                    ],
                ],
                'temperature' => 0.7,
                'max_tokens' => 10,
            ]);

            echo "HTTP Status: " . $response->status() . "\n";
            
            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                echo "Response: " . ($content ?? 'No content') . "\n";
                echo "Status: SUCCESS ✓\n";
                
                $this->assertTrue($response->successful());
                $this->assertNotEmpty($content);
            } else {
                $error = $response->json('error.message') ?? 'Unknown error';
                echo "Error: {$error}\n";
                echo "Response Body: " . $response->body() . "\n";
                echo "Status: FAILED ✗\n";
                
                $this->fail("API call failed: {$error}");
            }

        } catch (\Exception $e) {
            echo "Exception: " . $e->getMessage() . "\n";
            echo "Status: EXCEPTION ✗\n";
            
            $this->fail("API connectivity test failed: " . $e->getMessage());
        }

        echo "=============================\n";
    }

    public function test_api_model_availability(): void
    {
        $model = config('openrouter.model');
        
        echo "\n=== MODEL AVAILABILITY TEST ===\n";
        echo "Testing model: {$model}\n";
        
        // Test if the model format is correct
        $this->assertStringContainsString('/', $model, 'Model should be in format provider/model-name');
        
        $parts = explode('/', $model);
        $this->assertCount(2, $parts, 'Model should have provider and model name');
        
        echo "Provider: {$parts[0]}\n";
        echo "Model Name: {$parts[1]}\n";
        echo "Format: VALID ✓\n";
        echo "===============================\n";
    }

    public function test_configuration_loading(): void
    {
        echo "\n=== CONFIGURATION LOADING ===\n";
        
        // Test direct env access
        $envApiKey = env('OPENROUTER_API_KEY');
        $envModel = env('OPENROUTER_MODEL');
        
        // Test config access
        $configApiKey = config('openrouter.api_key');
        $configModel = config('openrouter.model');
        
        echo "ENV API Key: " . ($envApiKey ? 'SET' : 'NOT SET') . "\n";
        echo "ENV Model: " . ($envModel ?: 'NOT SET') . "\n";
        echo "Config API Key: " . ($configApiKey ? 'SET' : 'NOT SET') . "\n";
        echo "Config Model: " . ($configModel ?: 'NOT SET') . "\n";
        
        $this->assertEquals($envApiKey, $configApiKey, 'Config should match env for API key');
        $this->assertEquals($envModel, $configModel, 'Config should match env for model');
        
        echo "Consistency: VALID ✓\n";
        echo "=============================\n";
    }
}
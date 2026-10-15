<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class LoggingTest extends TestCase
{
    use IsolatesDatabase;

    public function test_logging_configuration_retains_10_days_daily(): void
    {
        $this->assertEquals(10, config('logging.channels.daily.days'));
        $this->assertEquals('daily', config('logging.channels.daily.driver'));
    }

    public function test_client_log_endpoint_ingests_frontend_errors(): void
    {
        Log::spy();

        $response = $this->postJson('/api/logs/client', [
            'level' => 'error',
            'message' => 'React component crashed',
            'context' => [
                'componentStack' => 'at TaskDetail (TaskDetail.jsx:10)',
            ],
            'url' => 'http://localhost/app/tasks/1',
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true]);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'error'
                    && str_contains($message, '[Frontend] React component crashed')
                    && isset($context['componentStack']);
            });
    }

    public function test_client_log_endpoint_validates_level(): void
    {
        $response = $this->postJson('/api/logs/client', [
            'level' => 'invalid_level',
            'message' => 'Something happened',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['level']);
    }

    public function test_http_request_logger_runs_on_routes(): void
    {
        $response = $this->get('/api/health');

        $response->assertOk();
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }
}

<?php

namespace Tests\Feature;

use App\Services\OllamaHttpClient;
use App\Support\RemoteLlmProvider;
use App\Support\SocKnowledgeRetriever;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class OllamaTlsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('soc.ollama_verify_tls', true);
        Config::set('soc.ollama_ca_cert', '');
    }

    public function test_plain_http_preserves_existing_transport_options(): void
    {
        $options = OllamaHttpClient::request('http://ollama:11434', 9)->getOptions();

        $this->assertArrayNotHasKey('verify', $options);
        $this->assertSame(9, $options['timeout']);
    }

    public function test_https_uses_configured_private_ca(): void
    {
        $caCert = tempnam(sys_get_temp_dir(), 'ollama-ca-');
        $this->assertNotFalse($caCert);
        Config::set('soc.ollama_ca_cert', $caCert);

        try {
            $options = OllamaHttpClient::request('https://ollama.example', 7)->getOptions();
            $this->assertSame($caCert, $options['verify']);
        } finally {
            @unlink($caCert);
        }
    }

    public function test_https_uses_system_trust_when_ca_is_unset(): void
    {
        $options = OllamaHttpClient::request('https://ollama.example', 7)->getOptions();

        $this->assertArrayNotHasKey('verify', $options);
    }

    public function test_explicit_insecure_mode_is_visible_in_request_options(): void
    {
        Config::set('soc.ollama_verify_tls', false);

        $options = OllamaHttpClient::request('https://ollama.example', 7)->getOptions();

        $this->assertFalse($options['verify']);
    }

    public function test_https_fails_closed_when_private_ca_is_missing(): void
    {
        Config::set('soc.ollama_ca_cert', '/missing/ollama-ca.crt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama CA certificate not found');
        OllamaHttpClient::request('https://ollama.example', 7);
    }

    public function test_private_ca_is_rejected_for_plaintext_url(): void
    {
        Config::set('soc.ollama_ca_cert', '/configured/ollama-ca.crt');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an HTTPS Ollama URL');
        OllamaHttpClient::request('http://ollama:11434', 7);
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OllamaHttpClient::request('file:///etc/passwd', 7);
    }

    public function test_generation_path_falls_back_before_network_on_invalid_ca(): void
    {
        Config::set('soc.ollama_base_url', 'https://ollama.example');
        Config::set('soc.ollama_ca_cert', '/missing/generation-ca.crt');
        Http::fake();

        $result = (new RemoteLlmProvider('ollama'))->generate('summary', ['_prompt' => 'summarize']);

        $this->assertSame('local-heuristic', $result['provider_fallback']);
        $this->assertStringContainsString('Ollama CA certificate not found', $result['provider_error']);
        Http::assertNothingSent();
    }

    public function test_embedding_path_falls_back_before_network_on_invalid_ca(): void
    {
        Config::set('soc.embedding_model_url', 'https://ollama.example');
        Config::set('soc.ollama_ca_cert', '/missing/embedding-ca.crt');
        Http::fake();

        $method = new ReflectionMethod(SocKnowledgeRetriever::class, 'embeddingVector');
        $vector = $method->invoke(new SocKnowledgeRetriever, 'bounded defensive content');

        $this->assertCount(32, $vector);
        Http::assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AiChatService
{
    private const SYSTEM_PROMPT = 'Eres Nubecita IA. Responde en español claro, útil y breve. Si no sabes algo, dilo con honestidad.';

    public function __construct(
        #[Autowire('%env(string:AI_PROVIDER)%')]
        private readonly string $provider,
        #[Autowire('%env(string:OPENAI_API_KEY)%')]
        private readonly string $openAiApiKey,
        #[Autowire('%env(string:AI_MODEL)%')]
        private readonly string $model,
        #[Autowire('%env(int:AI_TIMEOUT_SECONDS)%')]
        private readonly int $timeoutSeconds,
        #[Autowire('%env(int:AI_MAX_OUTPUT_TOKENS)%')]
        private readonly int $maxOutputTokens,
        #[Autowire('%env(bool:AI_FALLBACK_LOCAL)%')]
        private readonly bool $allowLocalFallback,
    ) {
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    public function generateReply(array $history): string
    {
        $provider = strtolower(trim($this->provider));

        if ('mock' === $provider) {
            return $this->localReply($history);
        }

        if ('openai' !== $provider) {
            throw new \RuntimeException('Proveedor IA no soportado.');
        }

        try {
            return $this->openAiReplyWithRecovery($history);
        } catch (\Throwable $exception) {
            if ($this->allowLocalFallback) {
                return $this->localReply($history);
            }

            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    public function localReply(array $history): string
    {
        return '[Modo local] '.$this->mockReply($history);
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    private function mockReply(array $history): string
    {
        $latest = '';
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if (($history[$i]['role'] ?? '') === 'user') {
                $latest = trim((string) ($history[$i]['content'] ?? ''));
                break;
            }
        }

        if ('' === $latest) {
            return 'Soy Nubecita IA. Dime en qué te ayudo.';
        }

        return 'Nubecita IA: Recibí tu mensaje "'.mb_substr($latest, 0, 120).'" y estoy aquí para ayudarte.';
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    private function openAiReplyWithRecovery(array $history): string
    {
        if ('' === trim($this->openAiApiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY no está configurada.');
        }

        $historyVariants = [
            $this->normalizeHistory($history, 18, 7000),
            $this->normalizeHistory($history, 8, 3400),
            $this->normalizeHistory($history, 2, 1200),
        ];

        $lastError = 'OpenAI no respondió.';

        foreach ($historyVariants as $messages) {
            if ([] === $messages) {
                continue;
            }

            try {
                return $this->requestResponsesApi($messages);
            } catch (\Throwable $firstError) {
                $lastError = $firstError->getMessage();

                try {
                    return $this->requestChatCompletionsApi($messages);
                } catch (\Throwable $secondError) {
                    $lastError = $secondError->getMessage();
                    if ($this->isRetriableError($lastError)) {
                        usleep(250000);
                    }
                }
            }
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @return list<array{role:string,content:string}>
     */
    private function normalizeHistory(array $history, int $maxMessages, int $maxTotalChars): array
    {
        $prepared = [];
        foreach ($history as $item) {
            $role = strtolower(trim((string) ($item['role'] ?? 'user')));
            if (!in_array($role, ['user', 'assistant', 'system'], true)) {
                $role = 'user';
            }

            $content = trim((string) ($item['content'] ?? ''));
            if ('' === $content) {
                continue;
            }

            $prepared[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 1400),
            ];
        }

        if ([] === $prepared) {
            $prepared[] = ['role' => 'user', 'content' => 'Hola'];
        }

        if (count($prepared) > $maxMessages) {
            $prepared = array_slice($prepared, -$maxMessages);
        }

        $running = 0;
        $trimmed = [];
        for ($i = count($prepared) - 1; $i >= 0; --$i) {
            $msg = $prepared[$i];
            $len = mb_strlen($msg['content']);
            if ($running + $len > $maxTotalChars) {
                continue;
            }
            $running += $len;
            array_unshift($trimmed, $msg);
        }

        return $trimmed;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     */
    private function requestResponsesApi(array $messages): string
    {
        $input = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($messages as $message) {
            $input[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $payload = [
            'model' => $this->model,
            'input' => $input,
            'max_output_tokens' => max(16, $this->maxOutputTokens),
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload);

        if (isset($response['output_text']) && is_string($response['output_text']) && '' !== trim($response['output_text'])) {
            return trim($response['output_text']);
        }

        $text = $this->extractText($response);
        if ('' === $text) {
            throw new \RuntimeException('OpenAI respondió sin texto (responses API).');
        }

        return $text;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     */
    private function requestChatCompletionsApi(array $messages): string
    {
        $payloadMessages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($messages as $message) {
            $payloadMessages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $payload = [
            'model' => $this->model,
            'messages' => $payloadMessages,
            'max_tokens' => max(16, min(300, $this->maxOutputTokens)),
            'temperature' => 0.5,
        ];

        $response = $this->postJson('https://api.openai.com/v1/chat/completions', $payload);

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');
        $content = trim($content);
        if ('' === $content) {
            throw new \RuntimeException('OpenAI respondió sin texto (chat completions API).');
        }

        return $content;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if (false === $ch) {
                throw new \RuntimeException('No se pudo inicializar cURL para IA.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$this->openAiApiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => max(8, $this->timeoutSeconds),
            ]);

            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (false === $raw) {
                throw new \RuntimeException('No se pudo conectar con OpenAI: '.$error);
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Respuesta inválida de OpenAI.');
            }

            if ($status < 200 || $status >= 300) {
                $apiMessage = (string) (($decoded['error']['message'] ?? '') ?: 'Error sin detalle');
                $apiCode = (string) (($decoded['error']['code'] ?? '') ?: 'unknown');
                throw new \RuntimeException(sprintf('OpenAI HTTP %d (%s): %s', $status, $apiCode, trim($apiMessage)));
            }

            return $decoded;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$this->openAiApiKey}\r\nContent-Type: application/json\r\n",
                'content' => $body,
                'timeout' => max(8, $this->timeoutSeconds),
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (false === $raw) {
            throw new \RuntimeException('No se pudo conectar con OpenAI.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta inválida de OpenAI.');
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $headerLine, $matches)) {
                $statusCode = (int) ($matches[1] ?? 0);
                break;
            }
        }

        if ($statusCode > 0 && ($statusCode < 200 || $statusCode >= 300)) {
            $apiMessage = (string) (($decoded['error']['message'] ?? '') ?: 'Error sin detalle');
            $apiCode = (string) (($decoded['error']['code'] ?? '') ?: 'unknown');
            throw new \RuntimeException(sprintf('OpenAI HTTP %d (%s): %s', $statusCode, $apiCode, trim($apiMessage)));
        }

        return $decoded;
    }

    private function isRetriableError(string $message): bool
    {
        $msg = strtolower($message);

        return str_contains($msg, 'http 429')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'temporarily')
            || str_contains($msg, 'overloaded')
            || str_contains($msg, 'rate limit');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractText(array $payload): string
    {
        $texts = [];

        $walker = function (mixed $value) use (&$walker, &$texts): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_string($k) && in_array($k, ['text', 'output_text'], true) && is_string($v) && '' !== trim($v)) {
                        $texts[] = trim($v);
                    }
                    $walker($v);
                }
            }
        };

        $walker($payload);

        return trim(implode("\n", array_values(array_unique($texts))));
    }
}

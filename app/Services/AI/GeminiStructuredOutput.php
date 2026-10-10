<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use JsonException;

class GeminiStructuredOutput
{
    /**
     * @return array{data: mixed, diagnostic: array<string, mixed>}
     */
    public static function extract(Response $response): array
    {
        $body = $response->json();
        if (! is_array($body) || ! is_array($body['candidates'] ?? null) || $body['candidates'] === []) {
            return self::failure('missing_candidates', $body);
        }

        $candidate = $body['candidates'][0] ?? null;
        if (! is_array($candidate)) {
            return self::failure('invalid_candidate', $body);
        }

        $finishReason = is_string($candidate['finishReason'] ?? null)
            ? $candidate['finishReason']
            : null;
        $parts = $candidate['content']['parts'] ?? null;
        if (! is_array($parts) || $parts === []) {
            return self::failure('missing_parts', $body, $finishReason);
        }

        $texts = [];
        foreach ($parts as $part) {
            if (! is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }

            if (is_string($part['text'] ?? null) && $part['text'] !== '') {
                $texts[] = $part['text'];
            }
        }

        if ($texts === []) {
            return self::failure('thought_only_or_empty_text', $body, $finishReason);
        }

        $raw = trim(implode('', $texts));
        $raw = self::stripMarkdownFence($raw);

        try {
            return [
                'data' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR),
                'diagnostic' => [
                    'parse_error' => null,
                    'finish_reason' => $finishReason,
                    'candidate_count' => count($body['candidates']),
                    'text_part_count' => count($texts),
                ],
            ];
        } catch (JsonException) {
            return self::failure('invalid_json', $body, $finishReason, count($texts));
        }
    }

    /**
     * @return array{data: null, diagnostic: array<string, mixed>}
     */
    private static function failure(
        string $parseError,
        mixed $body,
        ?string $finishReason = null,
        int $textPartCount = 0
    ): array {
        $candidateCount = is_array($body) && is_array($body['candidates'] ?? null)
            ? count($body['candidates'])
            : 0;

        return [
            'data' => null,
            'diagnostic' => [
                'parse_error' => $parseError,
                'finish_reason' => $finishReason,
                'candidate_count' => $candidateCount,
                'text_part_count' => $textPartCount,
            ],
        ];
    }

    private static function stripMarkdownFence(string $text): string
    {
        if (! str_starts_with($text, '```')) {
            return $text;
        }

        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;

        return preg_replace('/\s*```$/', '', $text) ?? $text;
    }
}

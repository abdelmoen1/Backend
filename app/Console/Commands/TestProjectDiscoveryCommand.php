<?php

namespace App\Console\Commands;

use App\Services\AI\ProjectDiscoveryException;
use App\Services\AI\ProjectDiscoveryService;
use Illuminate\Console\Command;

class TestProjectDiscoveryCommand extends Command
{
    protected $signature = 'ai:test-project-discovery';

    protected $description = 'Send a sample project context to Gemini and validate its structured response.';

    public function handle(ProjectDiscoveryService $discovery): int
    {
        try {
            $result = $discovery->nextStep([
                'organization' => [
                    'type' => 'عيادة',
                ],
                'project' => [
                    'title' => 'نظام إدارة مواعيد المرضى',
                    'problem' => 'نريد نظامًا يساعدنا على تنظيم مواعيد المرضى وتقليل تداخل المواعيد.',
                    'current_process' => 'واتساب ودفتر ورقي.',
                ],
                'history' => [],
            ]);
        } catch (ProjectDiscoveryException $exception) {
            $this->error($exception->getMessage());

            if ($exception->providerDiagnostic !== null) {
                $diagnostic = $exception->providerDiagnostic;
                $this->line('Provider HTTP status: '.($diagnostic['http_status'] ?? 'unknown'));
                $this->line('Provider category: '.($diagnostic['provider_status'] ?? 'unspecified'));
                if ($diagnostic['provider_message'] ?? null) {
                    $this->line('Provider message: '.$diagnostic['provider_message']);
                }
            }

            return self::FAILURE;
        }

        $this->info('Gemini request succeeded; structured response validated.');
        $this->line(json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ));

        return self::SUCCESS;
    }
}

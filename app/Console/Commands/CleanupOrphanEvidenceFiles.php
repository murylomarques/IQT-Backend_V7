<?php

namespace App\Console\Commands;

use App\Services\EvidenceFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanEvidenceFiles extends Command
{
    protected $signature = 'storage:cleanup-orphan-evidence {--delete : Delete orphan files. Without this option, only reports what would be deleted.}';

    protected $description = 'Reports or deletes evidence files that are no longer referenced in the database.';

    private array $directories = [
        'vistorias',
        'correcoes',
        'vistorias_seguranca',
    ];

    public function handle(): int
    {
        $delete = (bool) $this->option('delete');
        $referencedPaths = $this->referencedPaths();
        $disk = Storage::disk('public');
        $orphanFiles = [];
        $orphanBytes = 0;

        foreach ($this->directories as $directory) {
            foreach ($disk->allFiles($directory) as $file) {
                $normalizedFile = EvidenceFileService::normalizePath($file);

                if ($normalizedFile && !isset($referencedPaths[$normalizedFile])) {
                    $orphanFiles[] = $normalizedFile;
                    $orphanBytes += $disk->size($normalizedFile);
                }
            }
        }

        $this->info('Arquivos órfãos encontrados: ' . count($orphanFiles));
        $this->info('Espaço recuperável: ' . $this->formatBytes($orphanBytes));

        if (!$delete) {
            $this->warn('Simulação apenas. Execute com --delete para apagar.');
            return self::SUCCESS;
        }

        foreach ($orphanFiles as $file) {
            $disk->delete($file);
        }

        $this->info('Arquivos órfãos apagados: ' . count($orphanFiles));

        return self::SUCCESS;
    }

    private function referencedPaths(): array
    {
        $paths = [];

        $this->addReferencedColumn($paths, 'vistoria_checklist_itens', 'foto_path');
        $this->addReferencedColumn($paths, 'vistoria_checklist_itens', 'foto_correcao_path');
        $this->addReferencedColumn($paths, 'vistoria_seguranca_arquivos', 'path');

        return $paths;
    }

    private function addReferencedColumn(array &$paths, string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->whereNotNull($column)
            ->orderBy('id')
            ->pluck($column)
            ->each(function ($path) use (&$paths) {
                $normalizedPath = EvidenceFileService::normalizePath($path);

                if ($normalizedPath) {
                    $paths[$normalizedPath] = true;
                }
            });
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}

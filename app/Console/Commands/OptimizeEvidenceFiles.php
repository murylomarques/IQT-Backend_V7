<?php

namespace App\Console\Commands;

use App\Services\EvidenceFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OptimizeEvidenceFiles extends Command
{
    protected $signature = 'storage:optimize-evidence
        {--write : Otimiza, atualiza o banco e remove os originais substituidos. Sem esta opcao, apenas simula.}
        {--keep-originals : Mantem os arquivos originais depois de atualizar o banco.}
        {--delete-orphans : Apaga arquivos orfaos depois da otimizacao.}';

    protected $description = 'Optimizes active evidence images already referenced in the database.';

    private array $referenceColumns = [
        ['table' => 'vistoria_checklist_itens', 'column' => 'foto_path'],
        ['table' => 'vistoria_checklist_itens', 'column' => 'foto_correcao_path'],
        ['table' => 'vistoria_seguranca_arquivos', 'column' => 'path'],
    ];

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $keepOriginals = (bool) $this->option('keep-originals');
        $deleteOrphans = (bool) $this->option('delete-orphans');

        if ($keepOriginals && $deleteOrphans) {
            $this->error('Use apenas uma das opcoes: --keep-originals ou --delete-orphans.');
            return self::FAILURE;
        }

        $references = $this->referencedPaths();
        $candidates = $this->optimizationCandidates($references);

        $this->info('Imagens ativas encontradas para otimizar: ' . count($candidates));
        $this->info('Espaco atual dessas imagens: ' . $this->formatBytes(array_sum(array_column($candidates, 'size'))));

        if (!EvidenceFileService::canOptimizeImages()) {
            $this->warn('Este PHP nao tem GD/WebP habilitado. Para gravar a otimizacao, instale/habilite php-gd com suporte a WebP.');

            if ($write) {
                return self::FAILURE;
            }
        }

        if (!$write) {
            $this->warn('Simulacao apenas. Execute com --write para otimizar imagens ativas.');

            if ($deleteOrphans) {
                $this->call('storage:cleanup-orphan-evidence');
            }

            return self::SUCCESS;
        }

        $optimized = 0;
        $failed = 0;
        $newBytes = 0;

        foreach ($candidates as $oldPath => $candidate) {
            try {
                $newPath = EvidenceFileService::optimizeStoredImage($oldPath);

                DB::transaction(function () use ($candidate, $newPath) {
                    foreach ($candidate['references'] as $reference) {
                        DB::table($reference['table'])
                            ->where('id', $reference['id'])
                            ->where($reference['column'], $reference['value'])
                            ->update([$reference['column'] => $newPath]);
                    }
                });

                $newBytes += Storage::disk('public')->size($newPath);

                if (!$keepOriginals) {
                    EvidenceFileService::delete($oldPath);
                }

                $optimized++;
            } catch (\Throwable $e) {
                if (isset($newPath)) {
                    EvidenceFileService::delete($newPath);
                }

                $failed++;
                $this->warn("Falha ao otimizar {$oldPath}: {$e->getMessage()}");
            } finally {
                unset($newPath);
            }
        }

        $this->info("Imagens otimizadas: {$optimized}");
        $this->info("Falhas: {$failed}");
        $this->info('Espaco novo das imagens otimizadas: ' . $this->formatBytes($newBytes));

        if ($deleteOrphans) {
            $this->call('storage:cleanup-orphan-evidence', ['--delete' => true]);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function referencedPaths(): array
    {
        $paths = [];

        foreach ($this->referenceColumns as $referenceColumn) {
            $table = $referenceColumn['table'];
            $column = $referenceColumn['column'];

            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->select(['id', $column])
                ->whereNotNull($column)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$paths, $table, $column) {
                    foreach ($rows as $row) {
                        $value = $row->{$column};
                        $normalizedPath = EvidenceFileService::normalizePath($value);

                        if (!$normalizedPath) {
                            continue;
                        }

                        $paths[$normalizedPath]['references'][] = [
                            'table' => $table,
                            'column' => $column,
                            'id' => $row->id,
                            'value' => $value,
                        ];
                    }
                });
        }

        return $paths;
    }

    private function optimizationCandidates(array $references): array
    {
        $candidates = [];

        foreach ($references as $path => $data) {
            $info = EvidenceFileService::storedFileInfo($path);

            if (!$info || !$info['is_supported_image'] || $info['is_optimized']) {
                continue;
            }

            $candidates[$path] = [
                'size' => $info['size'],
                'references' => $data['references'],
            ];
        }

        return $candidates;
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

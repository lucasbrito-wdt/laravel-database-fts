<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Services;

use Illuminate\Support\Collection;

/**
 * Class SearchService
 *
 * Serviço para busca multi-model com ranking unificado.
 * Permite buscar em múltiplos models simultaneamente e combinar resultados.
 *
 * @package LucasBritoWdt\LaravelDatabaseFts\Services
 */
class SearchService
{
    /**
     * Models registrados para busca.
     *
     * @var array
     */
    protected array $models = [];

    /**
     * Registra um model para busca.
     *
     * @param string $model Nome da classe do model
     * @return $this
     */
    public function register(string $model): self
    {
        if (!in_array($model, $this->models)) {
            $this->models[] = $model;
        }

        return $this;
    }

    /**
     * Busca em todos os models registrados.
     *
     * @param string $term Termo de busca
     * @param float|null $similarity Threshold de similaridade (opcional)
     * @param array $acl Array de valores de visibilidade permitidos
     * @param int $limit Limite de resultados por model
     * @return array
     */
    public function search(
        string $term,
        ?float $similarity,
        array $acl = [],
        int $limit = 20
    ): array {
        $results = [];

        foreach ($this->models as $model) {
            if (!method_exists($model, 'search')) {
                continue;
            }

            $query = $model::search($term, $similarity, $acl)
                ->limit($limit);

            $items = $query->get();

            foreach ($items as $item) {
                // Calcula score se disponível (similarity retorna valor entre 0 e 1)
                $score = null;
                if (method_exists($item, 'getAttribute')) {
                    // Tenta obter score do ranking (pode ser calculado via selectRaw)
                    $score = $item->getAttribute('rank') ?? $item->getAttribute('score');
                }

                $results[] = [
                    'type' => class_basename($model),
                    'model' => $model,
                    'score' => $score,
                    'data' => $item,
                ];
            }
        }

        // Ordena por score (maior primeiro) e retorna
        return collect($results)
            ->sortByDesc('score')
            ->values()
            ->toArray();
    }

    /**
     * Retorna os models registrados.
     *
     * @return array
     */
    public function getRegisteredModels(): array
    {
        return $this->models;
    }

    /**
     * Limpa os models registrados.
     *
     * @return $this
     */
    public function clear(): self
    {
        $this->models = [];

        return $this;
    }
}

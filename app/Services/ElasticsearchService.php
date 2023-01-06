<?php

namespace App\Services;

use Throwable;
use App\Facades\Log;
use App\Supports\Response;
use App\Services\LogService;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Elastic\Elasticsearch\ClientBuilder;
use App\Exceptions\ElasticsearchConnectionException;

/**
 * @author Rahmat Setiawan <setiawaneggy@gmail.com>
 */
class ElasticsearchService
{
    /**
     * Initiate elastic search
     */
    protected Client $client;

    public function __construct()
    {
        try {
            $this->client = ClientBuilder::create()->setHosts([config('elasticsearch.host')])->build();
        } catch (\Throwable $e) {
            throw new Exception($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get Elasticsearch info
     */
    public function info(): array
    {
        $response = $this->client->info();

        return $response['version'];
    }

    /**
     * Get Elasticsearch version
     */
    public function version(): string
    {
        $response = $this->client->info();

        return $response['version']['number'];
    }

    protected string $index;

    /**
     * set Index
     */
    public function index(string $index): object
    {
        $this->index = $index;

        $this->params = [
            'index' => $index,
            'size' => $this->size,
        ];

        return $this;
    }

    /**
     * Bulk insert data to document
     */
    public function insert(array $body)
    {
        $params = [];
        foreach ($body as $data) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->index,
                ],
            ];

            $params['body'][] = $data;
        }

        try {
            $this->client->bulk($params);

            return true;
        } catch (Throwable $e) {
            Log::channel(LogService::ELASTIC)->error('Failed to bulk Insert', [
                'params' => $params,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Insert data to document
     */
    public function create(array $body): bool
    {
        $params = [
            'index' => $this->index,
            'body' => $body,
        ];

        try {
            $this->client->index($params);

            return true;
        } catch (Throwable $e) {
            Log::channel(LogService::ELASTIC)->error('Failed to Insert', [
                'params' => $params,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete data from elasticsearch
     */
    public function delete(): bool
    {
        $params = [];

        // delete index
        if (func_num_args() === 0) {
            try {
                $params = [
                    'index' => $this->index,
                ];

                $this->client->indices()->delete($params);

                return true;
            } catch (\Throwable $e) {
                Log::channel(LogService::ELASTIC)->error('Failed to delete index', [
                    'params' => $params,
                    'message' => $e->getMessage(),
                ]);

                return false;
            }
        }

        // delete document
        try {
            if (is_array($id = func_get_args()[0])) {
                foreach ($id as $data) {
                    $params['body'][] = [
                        'delete' => [
                            '_index' => $this->index,
                            '_id' => $data['_id'],
                        ],
                    ];
                }
                $this->client->bulk($params);

                return true;
            }

            $params = [
                'index' => $this->index,
                'id' => $id,
            ];

            $this->client->delete($params);

            return true;
        } catch (\Throwable $e) {
            Log::channel(LogService::ELASTIC)->error('Failed to delete data', [
                'params' => $params,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Default size of limit result
     */
    protected int $size = 10000;

    /**
     * Params
     */
    protected array $params;

    /**
     * all result from elastic
     */
    public function all(): array
    {
        $response = $this->client->search($this->params);

        return $response['hits']['hits'];
    }

    /**
     * fetched all results as collection
     */
    public function get(): Collection
    {
        $response = $this->client->search($this->params);

        $collection = collect();

        foreach ($response['hits']['hits'] as $response) {
            $collection->push($response['_source']);
        }

        return $collection;
    }

    /**
     * fetched all results as lazy collection
     */
    public function lazy(): Collection
    {
        $responses = $this->client->search($this->params);

        $collection = LazyCollection::make(function () use ($responses) {
            foreach ($responses['hits']['hits'] as $response) {
                yield $response['_source'];
            }
        });

        return $collection->collect();
    }

    /**
     * Transform source to array
     */
    public function toArray(): array
    {
        $responses = $this->client->search($this->params);

        $data = [];
        foreach ($responses['hits']['hits'] as $response) {
            $data[] = $response['_source'];
        }

        return $data;
    }

    /**
     * Filter result by key and value
     */
    public function where(array $array): object
    {
        $match = [];

        foreach ($array as $key => $val) {
            $match[] = [
                'match' => [
                    $key => $val,
                ],
            ];
        }

        $params = [
            'index' => $this->index,

            'body' => [
                'query' => [
                    'bool' => [
                        'must' => $match,
                    ],
                ],
            ],

            'size' => $this->size,
        ];


        $this->params = $params;

        return $this;
    }

    /**
     * Filter results using wildcard
     */
    public function whereLike(string $key, string $val): object
    {
        $params = [
            'index' => $this->index,
            'body' => [
                'query' => [
                    'wildcard' => [
                        $key => $val,
                    ],
                ],
                'size' => $this->size,
            ],
        ];

        $this->params = $params;

        return $this;
    }

    /**
     * Limit fetched data
     */
    public function limit(int $limit): object
    {
        $this->params['size'] = $limit;

        return $this;
    }

    /**
     * Determine if data is exists in document
     */
    public function exists(): bool
    {
        $response = $this->client->search($this->params);

        if (empty($response['hits']['hits'])) {
            return false;
        }

        return true;
    }

    /**
     * Simple search using a match _all query
     */
    public function search(string $term): object
    {
        $params = [];

        $params['index'] = $this->index;

        $params['body']['query']['match']['_all'] = $term;

        $params['size'] = $this->size;

        $this->params = $params;

        return $this;
    }
}

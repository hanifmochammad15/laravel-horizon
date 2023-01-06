<?php

namespace App\Services;

use App\Facades\ES;
use Illuminate\Support\Facades\Log;
use App\Services\JsonLogFormatterService;

class LogService extends JsonLogFormatterService
{
    public const ACTION = 'action';

    public const ELASTIC = 'elastic';

    public const BRIGATE = 'brigate';

    public const EBOR = 'ebor';

    public const INFO = 'info';

    public const ERROR = 'error';

    protected string $channel;

    protected string $channelDaily;

    public function __construct()
    {
        $this->channel = strtolower(config('app.name'));

        $this->channelDaily = sprintf('%s-%s', $this->channel, now()->toDateString());
    }

    public function channel(string $name): object
    {
        $this->channel = $name;

        $this->channelDaily = sprintf('%s-%s', $name, now()->toDateString());

        return $this;
    }

    public function info(string $message, array $context = []): void
    {
        $this->writeLog($message, $context, __FUNCTION__);
    }

    public function error(string $message, array $context = []): void
    {
        $this->writeLog($message, $context, __FUNCTION__);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->writeLog($message, $context, __FUNCTION__);
    }

    protected function writeLog(string $message, array $context, string $level): void
    {
        $logger = config('app.env');

        $record = [];
        $record['@timestamp'] = now()->format('Y-m-d\TH:i:s.000\Z');
        $record['level'] = $level;
        $record['logger'] = $logger;
        $record['message'] = $message;

        if ($this->channel === self::ACTION) {
            $record['updated_by'] = request()->user()->name ?? null;
        }

        $record['context'] = $context;

        $level = $record['level'];

        // write log to file

        // write log to elastic
        try {
            if (config('logging.to_file')) {
                $this->channel === config('app.name') ? Log::$level($message, $context) : Log::channel($this->channel)->$level($message, $context);
            }

            if (config('logging.to_elastic')) {
                ES::index($this->channelDaily)->create($record);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to write log to elastic search', [
                'message' => $e->getMessage(),
                'exception_in' => sprintf('%s@%s', __CLASS__, __FUNCTION__),
                'data' => $record,
            ]);
        }
    }
}

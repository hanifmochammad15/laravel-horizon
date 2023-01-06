<?php

namespace App\Services;

use Monolog\Formatter\NormalizerFormatter;

class JsonLogFormatterService extends NormalizerFormatter
{
    /** @var bool */
    protected $ignoreEmptyContextAndExtra;

    public function format(array $record): string
    {
        $normalized = $this->normalize($record);

        if (isset($normalized['context']) && $normalized['context'] === []) {
            if ($this->ignoreEmptyContextAndExtra) {
                unset($normalized['context']);
            } else {
                $normalized['context'] = new \stdClass();
            }
        }

        $message = [
            '@timestamp' => $this->normalize($record['datetime']),
            // 'dateString' => Carbon::parse($this->normalize($record['datetime']))->toDateTimeString(),
            'level' => $record['level_name'],
            'logger' => $record['channel'],
        ];

        if (isset($record['message'])) {
            $message['message'] = $record['message'];
        }

        $message['context'] = $normalized['context'];

        if (config('app.env') !== 'production') {
            return json_encode($message, JSON_PRETTY_PRINT)."\n";
        }

        return $this->toJson($message)."\n";
    }
}

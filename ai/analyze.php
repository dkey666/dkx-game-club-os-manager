<?php

require_once __DIR__ . '/ClubAnalyticsLight.php';

function parseArgs(array $argv): array
{
    $options = [
        'db' => null,
        'metrics-only' => false,
        'json' => false,
        'save' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--metrics-only') {
            $options['metrics-only'] = true;
            continue;
        }

        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }

        if (str_starts_with($arg, '--db=')) {
            $options['db'] = substr($arg, 5);
            continue;
        }

        if (str_starts_with($arg, '--save=')) {
            $options['save'] = substr($arg, 7);
            continue;
        }
    }

    return $options;
}

try {
    $options = parseArgs($argv);
    $module = new ClubAnalyticsLight($options['db']);
    $metrics = $module->collectMetrics();

    if ($options['metrics-only']) {
        $output = $options['json']
            ? json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
            : $module->renderConsoleReport($metrics);
    } else {
        $analysis = $module->analyzeWithOpenAI($metrics);
        $payload = [
            'metrics' => $metrics,
            'analysis' => $analysis,
        ];

        $output = $options['json']
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
            : $module->renderConsoleReport($metrics, $analysis);
    }

    echo $output;

    if ($options['save']) {
        $directory = dirname($options['save']);
        if ($directory !== '.' && !is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($options['save'], $output);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Analytics module error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

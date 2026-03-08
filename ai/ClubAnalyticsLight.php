<?php

require_once dirname(__DIR__) . '/config.php';

final class ClubAnalyticsLight
{
    private SQLite3 $db;
    private string $dbPath;

    public function __construct(?string $dbPath = null)
    {
        if (!class_exists('SQLite3')) {
            throw new RuntimeException('SQLite3 extension is required for the analytics module.');
        }

        $this->dbPath = $dbPath ?: Config::get('DB_NAME', 'club.db');
        if (!is_file($this->dbPath)) {
            throw new RuntimeException("Database file not found: {$this->dbPath}");
        }

        $this->db = new SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
        $this->db->busyTimeout(5000);
    }

    public function collectMetrics(): array
    {
        $overview = [
            'database_file' => $this->dbPath,
            'generated_at' => gmdate('c'),
            'total_users' => $this->querySingleInt('SELECT COUNT(*) FROM users'),
            'users_with_points' => $this->querySingleInt('SELECT COUNT(*) FROM users WHERE points > 0'),
            'registered_users' => $this->querySingleInt('SELECT COUNT(*) FROM users WHERE points_registered = 1'),
            'registrations_last_7_days' => $this->querySingleInt("SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-7 day')"),
            'registrations_last_30_days' => $this->querySingleInt("SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-30 day')"),
            'total_points_balance' => $this->querySingleInt('SELECT COALESCE(SUM(points), 0) FROM users'),
            'average_points_balance' => $this->querySingleInt('SELECT COALESCE(AVG(points), 0) FROM users'),
            'verified_emails' => $this->querySingleInt('SELECT COUNT(*) FROM users WHERE email_verified = 1'),
        ];

        $bookings = [
            'total' => $this->querySingleInt('SELECT COUNT(*) FROM bookings'),
            'last_7_days' => $this->querySingleInt("SELECT COUNT(*) FROM bookings WHERE created_at >= datetime('now', '-7 day')"),
            'by_status' => [
                'pending' => $this->querySingleInt("SELECT COUNT(*) FROM bookings WHERE status = 'pending'"),
                'approved' => $this->querySingleInt("SELECT COUNT(*) FROM bookings WHERE status = 'approved'"),
                'rejected' => $this->querySingleInt("SELECT COUNT(*) FROM bookings WHERE status = 'rejected'"),
            ],
            'by_hall' => $this->queryAll(
                "SELECT ((computer_id - 1) / 10) + 1 AS hall_id, COUNT(*) AS booking_count
                 FROM bookings
                 WHERE computer_id IS NOT NULL
                 GROUP BY hall_id
                 ORDER BY booking_count DESC, hall_id ASC"
            ),
            'top_computers' => $this->queryAll(
                "SELECT computer_id, COUNT(*) AS booking_count
                 FROM bookings
                 WHERE computer_id IS NOT NULL
                 GROUP BY computer_id
                 ORDER BY booking_count DESC, computer_id ASC
                 LIMIT 10"
            ),
            'busiest_weekdays' => $this->queryAll(
                "SELECT
                    CASE strftime('%w', created_at)
                        WHEN '0' THEN 'Sunday'
                        WHEN '1' THEN 'Monday'
                        WHEN '2' THEN 'Tuesday'
                        WHEN '3' THEN 'Wednesday'
                        WHEN '4' THEN 'Thursday'
                        WHEN '5' THEN 'Friday'
                        WHEN '6' THEN 'Saturday'
                    END AS weekday,
                    COUNT(*) AS booking_count
                 FROM bookings
                 GROUP BY strftime('%w', created_at)
                 ORDER BY booking_count DESC"
            ),
            'daily_trend_last_14_days' => $this->queryAll(
                "SELECT DATE(created_at) AS day, COUNT(*) AS booking_count
                 FROM bookings
                 WHERE created_at >= datetime('now', '-14 day')
                 GROUP BY DATE(created_at)
                 ORDER BY day ASC"
            ),
        ];

        $engagement = [
            'tasks' => [
                'total_tasks' => $this->querySingleInt('SELECT COUNT(*) FROM tasks'),
                'completed_tasks_total' => $this->querySingleInt('SELECT COUNT(*) FROM user_tasks'),
                'clicked_tasks_total' => $this->querySingleInt('SELECT COUNT(*) FROM task_clicks'),
                'top_tasks' => $this->queryAll(
                    "SELECT t.id, t.title, COUNT(ut.id) AS completions
                     FROM tasks t
                     LEFT JOIN user_tasks ut ON ut.task_id = t.id
                     GROUP BY t.id, t.title
                     ORDER BY completions DESC, t.id ASC
                     LIMIT 10"
                ),
            ],
            'referrals' => [
                'total_referrals' => $this->querySingleInt('SELECT COUNT(*) FROM referrals'),
                'total_referral_rewards' => $this->querySingleInt('SELECT COALESCE(SUM(reward), 0) FROM referrals'),
                'last_30_days' => $this->querySingleInt("SELECT COUNT(*) FROM referrals WHERE created_at >= datetime('now', '-30 day')"),
            ],
            'daily_rewards' => [
                'claims_total' => $this->querySingleInt('SELECT COUNT(*) FROM daily_rewards'),
                'reward_sum_total' => $this->querySingleInt('SELECT COALESCE(SUM(reward_amount), 0) FROM daily_rewards'),
                'claims_last_30_days' => $this->querySingleInt("SELECT COUNT(*) FROM daily_rewards WHERE created_at >= datetime('now', '-30 day')"),
            ],
            'tap_rewards' => [
                'reward_events' => $this->querySingleInt("SELECT COUNT(*) FROM points_transactions WHERE reason = 'Клик'"),
                'reward_sum_total' => $this->querySingleInt("SELECT COALESCE(SUM(amount), 0) FROM points_transactions WHERE reason = 'Клик'"),
                'unique_users' => $this->querySingleInt("SELECT COUNT(DISTINCT user_id) FROM points_transactions WHERE reason = 'Клик'"),
            ],
            'hold_game_rewards' => [
                'reward_events' => $this->querySingleInt("SELECT COUNT(*) FROM points_transactions WHERE reason = 'Игра на удержание'"),
                'reward_sum_total' => $this->querySingleInt("SELECT COALESCE(SUM(amount), 0) FROM points_transactions WHERE reason = 'Игра на удержание'"),
            ],
            'points_economy' => [
                'total_transactions' => $this->querySingleInt('SELECT COUNT(*) FROM points_transactions'),
                'total_added' => $this->querySingleInt("SELECT COALESCE(SUM(amount), 0) FROM points_transactions WHERE action = 'add'"),
                'total_subtracted' => $this->querySingleInt("SELECT COALESCE(SUM(amount), 0) FROM points_transactions WHERE action = 'subtract'"),
                'transactions_last_30_days' => $this->querySingleInt("SELECT COUNT(*) FROM points_transactions WHERE created_at >= datetime('now', '-30 day')"),
            ],
            'rank_distribution' => $this->buildRankDistribution(),
        ];

        return [
            'scope' => 'light_sql_only',
            'limitations' => [
                'This version analyzes only data directly available in SQLite tables.',
                'It does not inspect UI behavior, frontend funnels, or hidden business logic from the PHP/JS code.',
                'Visitor-day analysis uses booking and registration activity as a practical proxy.',
            ],
            'overview' => $overview,
            'bookings' => $bookings,
            'engagement' => $engagement,
        ];
    }

    public function analyzeWithOpenAI(array $metrics): array
    {
        $apiKey = Config::get('OPENAI_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is missing. Add it to your local .env file.');
        }

        $payload = [
            'model' => Config::get('OPENAI_MODEL', 'gpt-5.4'),
            'temperature' => 0.2,
            'instructions' => implode("\n", [
                'You are an analytics assistant for a gaming club management platform.',
                'Analyze only the supplied metrics. Do not invent hidden events or code paths.',
                'Be concrete, operational, and concise.',
                'Return valid JSON that matches the supplied schema.',
            ]),
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "Analyze this gaming club metrics snapshot and produce operational insights.\n\nMetrics JSON:\n" . json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'club_analytics_report',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'summary' => ['type' => 'string'],
                            'behavior_insights' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'booking_insights' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'engagement_insights' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'recommendations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title' => ['type' => 'string'],
                                        'priority' => [
                                            'type' => 'string',
                                            'enum' => ['high', 'medium', 'low'],
                                        ],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['title', 'priority', 'reason'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'limitations' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => [
                            'summary',
                            'behavior_insights',
                            'booking_insights',
                            'engagement_insights',
                            'recommendations',
                            'limitations',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);

        $content = $this->extractOutputText($response);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI response was received but could not be parsed as JSON.');
        }

        return $decoded;
    }

    public function renderConsoleReport(array $metrics, ?array $analysis = null): string
    {
        $lines = [];
        $lines[] = 'DKX GAME CLUB ANALYTICS REPORT';
        $lines[] = str_repeat('=', 30);
        $lines[] = 'Generated at: ' . $metrics['overview']['generated_at'];
        $lines[] = 'Database: ' . $metrics['overview']['database_file'];
        $lines[] = '';
        $lines[] = 'Overview';
        $lines[] = '- Total users: ' . $metrics['overview']['total_users'];
        $lines[] = '- Users with points: ' . $metrics['overview']['users_with_points'];
        $lines[] = '- Registered users: ' . $metrics['overview']['registered_users'];
        $lines[] = '- Registrations last 30 days: ' . $metrics['overview']['registrations_last_30_days'];
        $lines[] = '- Total points balance: ' . $metrics['overview']['total_points_balance'];
        $lines[] = '';
        $lines[] = 'Bookings';
        $lines[] = '- Total bookings: ' . $metrics['bookings']['total'];
        $lines[] = '- Last 7 days: ' . $metrics['bookings']['last_7_days'];
        $lines[] = '- Statuses: ' . json_encode($metrics['bookings']['by_status'], JSON_UNESCAPED_UNICODE);
        $lines[] = '- Top halls: ' . $this->stringifyList($metrics['bookings']['by_hall'], 'hall_id', 'booking_count');
        $lines[] = '- Top computers: ' . $this->stringifyList($metrics['bookings']['top_computers'], 'computer_id', 'booking_count');
        $lines[] = '';
        $lines[] = 'Engagement';
        $lines[] = '- Tasks completed: ' . $metrics['engagement']['tasks']['completed_tasks_total'];
        $lines[] = '- Referrals: ' . $metrics['engagement']['referrals']['total_referrals'];
        $lines[] = '- Daily reward claims: ' . $metrics['engagement']['daily_rewards']['claims_total'];
        $lines[] = '- Tap reward events: ' . $metrics['engagement']['tap_rewards']['reward_events'];
        $lines[] = '';

        if ($analysis !== null) {
            $lines[] = 'AI Summary';
            $lines[] = $analysis['summary'];
            $lines[] = '';

            foreach ([
                'Behavior insights' => $analysis['behavior_insights'],
                'Booking insights' => $analysis['booking_insights'],
                'Engagement insights' => $analysis['engagement_insights'],
            ] as $label => $items) {
                $lines[] = $label;
                foreach ($items as $item) {
                    $lines[] = '- ' . $item;
                }
                $lines[] = '';
            }

            $lines[] = 'Recommendations';
            foreach ($analysis['recommendations'] as $item) {
                $lines[] = '- [' . strtoupper($item['priority']) . '] ' . $item['title'] . ': ' . $item['reason'];
            }
            $lines[] = '';
        }

        $lines[] = 'Module limitations';
        foreach ($metrics['limitations'] as $item) {
            $lines[] = '- ' . $item;
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function buildRankDistribution(): array
    {
        $rows = $this->queryAll('SELECT points FROM users');
        $distribution = [
            'Novice I' => 0,
            'Guard I' => 0,
            'Guard II' => 0,
            'Warrior I' => 0,
            'Warrior II' => 0,
            'Warrior III' => 0,
            'Champion I' => 0,
            'Champion II' => 0,
            'Legend' => 0,
            'Arena Lord' => 0,
        ];

        foreach ($rows as $row) {
            $points = (int) ($row['points'] ?? 0);
            if ($points >= 1000000) {
                $distribution['Arena Lord']++;
            } elseif ($points >= 600000) {
                $distribution['Legend']++;
            } elseif ($points >= 300000) {
                $distribution['Champion II']++;
            } elseif ($points >= 150000) {
                $distribution['Champion I']++;
            } elseif ($points >= 80000) {
                $distribution['Warrior III']++;
            } elseif ($points >= 40000) {
                $distribution['Warrior II']++;
            } elseif ($points >= 20000) {
                $distribution['Warrior I']++;
            } elseif ($points >= 10000) {
                $distribution['Guard II']++;
            } elseif ($points >= 5000) {
                $distribution['Guard I']++;
            } else {
                $distribution['Novice I']++;
            }
        }

        $result = [];
        foreach ($distribution as $rank => $users) {
            $result[] = ['rank' => $rank, 'users' => $users];
        }

        return $result;
    }

    private function querySingleInt(string $sql): int
    {
        $value = $this->db->querySingle($sql);
        return (int) ($value ?: 0);
    }

    private function queryAll(string $sql): array
    {
        $result = $this->db->query($sql);
        if (!$result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row === false) {
                break;
            }

            foreach ($row as $key => $value) {
                if (is_numeric($value) && (string) (int) $value === (string) $value) {
                    $row[$key] = (int) $value;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function postJson(string $url, array $payload, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ignore_errors' => true,
                'timeout' => 60,
            ],
        ]);

        $raw = file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Failed to call OpenAI Responses API.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI API returned invalid JSON.');
        }

        if (!empty($decoded['error'])) {
            $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'Unknown OpenAI error') : (string) $decoded['error'];
            throw new RuntimeException('OpenAI API error: ' . $message);
        }

        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        if (!empty($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        foreach ($response['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    return $content['text'];
                }
            }
        }

        throw new RuntimeException('OpenAI response did not contain output_text content.');
    }

    private function stringifyList(array $rows, string $labelKey, string $valueKey): string
    {
        if (!$rows) {
            return 'none';
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row[$labelKey] . ': ' . $row[$valueKey];
        }

        return implode(', ', $parts);
    }
}

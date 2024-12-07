<!DOCTYPE html>
<html>
<head>
    <title>Redmine Task Search</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .search-form {
            margin-bottom: 20px;
        }
        .search-form input[type="text"] {
            padding: 8px;
            width: 300px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .search-form button {
            padding: 8px 16px;
            background-color: #2996cc;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .search-form button:hover {
            background-color: #247aa7;
        }
        .task {
            border: 1px solid #ddd;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 4px;
        }
        .task:hover {
            background-color: #f9f9f9;
        }
        .task-title {
            font-size: 18px;
            color: #2996cc;
            margin-bottom: 8px;
        }
        .task-meta {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        .task-description {
            font-size: 14px;
            line-height: 1.4;
        }
        .status-new { color: #3498db; }
        .status-in-progress { color: #f1c40f; }
        .status-resolved { color: #2ecc71; }
        .status-closed { color: #95a5a6; }
        .priority-high { color: #e74c3c; }
        .priority-normal { color: #3498db; }
        .priority-low { color: #95a5a6; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Redmine Task Search</h1>

        <form class="search-form" method="GET">
            <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" placeholder="Enter search terms...">
            <button type="submit">Search</button>
        </form>

        <?php
        include 'config.php';

        class RedmineAPI {
            private $url;
            private $apiKey;

            public function __construct($url, $apiKey) {
                $this->url = rtrim($url, '/');
                $this->apiKey = $apiKey;
            }

            public function searchIssues($query) {
                $endpoint = "/issues.json";
                $params = http_build_query([
                    'subject' => '*' . $query . '*',
                    'description' => '*' . $query . '*',
                    'limit' => 100,
                    'status_id' => '*' // busca em todos os status
                ]);

                return $this->makeRequest($endpoint . '?' . $params);
            }

            private function makeRequest($endpoint) {
                $ch = curl_init($this->url . $endpoint);
                
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'X-Redmine-API-Key: ' . $this->apiKey,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_SSL_VERIFYPEER => false // Use apenas em desenvolvimento
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch)) {
                    throw new Exception('Curl error: ' . curl_error($ch));
                }

                curl_close($ch);

                if ($httpCode !== 200) {
                    throw new Exception('API request failed with code ' . $httpCode);
                }

                return json_decode($response, true);
            }

            public function getStatusClass($status) {
                switch(strtolower($status)) {
                    case 'new': return 'status-new';
                    case 'in progress': return 'status-in-progress';
                    case 'resolved': return 'status-resolved';
                    case 'closed': return 'status-closed';
                    default: return '';
                }
            }

            public function getPriorityClass($priority) {
                switch(strtolower($priority)) {
                    case 'high': return 'priority-high';
                    case 'normal': return 'priority-normal';
                    case 'low': return 'priority-low';
                    default: return '';
                }
            }
        }

        // Configurações
        $redmineUrl = 'http://criis-projects.inesctec.pt/';  // URL do seu Redmine
        $apiKey = $api_key;                 // Sua API key

        try {
            if (isset($_GET['q']) && !empty($_GET['q'])) {
                $api = new RedmineAPI($redmineUrl, $apiKey);
                $searchTerm = $_GET['q'];
                $results = $api->searchIssues($searchTerm);

                if (isset($results['issues']) && !empty($results['issues'])) {
                    echo "<h2>Results for: " . htmlspecialchars($searchTerm) . "</h2>";
                    foreach ($results['issues'] as $issue) {
                        $statusClass = $api->getStatusClass($issue['status']['name']);
                        $priorityClass = $api->getPriorityClass($issue['priority']['name']);
                        ?>
                        <div class="task">
                            <div class="task-title">
                                <a href="<?php echo $redmineUrl . '/issues/' . $issue['id']; ?>" target="_blank">
                                    #<?php echo $issue['id']; ?> - <?php echo htmlspecialchars($issue['subject']); ?>
                                </a>
                            </div>
                            <div class="task-meta">
                                Status: <span class="<?php echo $statusClass; ?>"><?php echo $issue['status']['name']; ?></span> |
                                Priority: <span class="<?php echo $priorityClass; ?>"><?php echo $issue['priority']['name']; ?></span> |
                                Project: <?php echo $issue['project']['name']; ?> |
                                Updated: <?php echo date('Y-m-d', strtotime($issue['updated_on'])); ?>
                            </div>
                            <div class="task-description">
                                <?php 
                                if (isset($issue['description'])) {
                                    echo nl2br(htmlspecialchars(substr($issue['description'], 0, 200)));
                                    if (strlen($issue['description']) > 200) echo '...';
                                }
                                ?>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo "<p>No results found for: " . htmlspecialchars($searchTerm) . "</p>";
                }
            }
        } catch (Exception $e) {
            echo "<div style='color: red; padding: 10px; background-color: #fee; border-radius: 4px;'>";
            echo "Error: " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>
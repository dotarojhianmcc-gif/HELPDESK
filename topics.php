<?php
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

ensureTopicsTable($pdo);
$topics = loadTopics();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'topics' => $topics]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? 'add';
$topic = trim($data['topic'] ?? '');

if ($action === 'reorder') {
    $submittedTopics = $data['topics'] ?? null;

    if (!is_array($submittedTopics)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid topic order']);
        exit;
    }

    $normalizedTopics = [];
    foreach ($submittedTopics as $submittedTopic) {
        $normalizedTopic = trim((string) $submittedTopic);
        if ($normalizedTopic === '' || in_array($normalizedTopic, $normalizedTopics, true)) {
            continue;
        }

        $normalizedTopics[] = $normalizedTopic;
    }

    $existingTopics = array_values(array_filter(array_map(static fn($existingTopic) => trim((string) $existingTopic), $topics)));
    $sortedExistingTopics = $existingTopics;
    $sortedNormalizedTopics = $normalizedTopics;
    natcasesort($sortedExistingTopics);
    natcasesort($sortedNormalizedTopics);

    if (array_values($sortedExistingTopics) !== array_values($sortedNormalizedTopics)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Topic order payload did not match saved topics']);
        exit;
    }

    if (saveTopics($normalizedTopics)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'topics' => $normalizedTopics, 'message' => 'Topic order updated']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unable to save topic order']);
    exit;
}

if ($action === 'delete') {
    if (!$topic) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid section']);
        exit;
    }
    $topicPrefix = $topic . ' / ';
    $filtered = array_values(array_filter($topics, function ($item) use ($topic, $topicPrefix) {
        return strcasecmp($item, $topic) !== 0 && stripos($item, $topicPrefix) !== 0;
    }));
    if (count($filtered) === count($topics)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Section not found']);
        exit;
    }
    if (saveTopics($filtered)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'topics' => $filtered, 'message' => 'Section removed']);
        exit;
    }
    header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unable to delete section']);
    exit;
}

if (!$topic) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Section cannot be empty']);
    exit;
}

$topicExists = false;
foreach ($topics as $existingTopic) {
    if (strcasecmp($existingTopic, $topic) === 0) {
        $topicExists = true;
        break;
    }
}

if ($topicExists) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Section already exists', 'topics' => $topics]);
    exit;
}

$pathsToEnsure = [];
$segments = array_values(array_filter(array_map('trim', explode(' / ', $topic)), fn($segment) => $segment !== ''));

if (empty($segments)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Section cannot be empty']);
    exit;
}

for ($index = 0; $index < count($segments); $index++) {
    $pathsToEnsure[] = implode(' / ', array_slice($segments, 0, $index + 1));
}

foreach ($pathsToEnsure as $path) {
    $exists = false;
    foreach ($topics as $existingTopic) {
        if (strcasecmp($existingTopic, $path) === 0) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $topics[] = $path;
    }
}
if (saveTopics($topics)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'topics' => $topics, 'message' => 'Section added']);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Unable to save topic']);

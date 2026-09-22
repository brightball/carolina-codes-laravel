<?php

/**
 * php -S router that speaks Gitea's /api/actions_pipeline artifact protocol.
 * State lives under FAKE_ARTIFACT_DIR so PHPUnit can round-trip a tarball.
 */
$storeDir = getenv('FAKE_ARTIFACT_DIR') ?: sys_get_temp_dir().'/fake-gitea-artifacts';
$token = getenv('FAKE_ARTIFACT_TOKEN') ?: 'test-token';
$runId = getenv('FAKE_ARTIFACT_RUN_ID') ?: '42';
$publicOrigin = getenv('FAKE_ARTIFACT_PUBLIC_ORIGIN') ?: '';

if (! is_dir($storeDir)) {
    mkdir($storeDir, 0755, true);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$parsed = parse_url($uri);
$path = $parsed['path'] ?? '/';
$query = [];
parse_str($parsed['query'] ?? '', $query);

if ($method === 'GET' && $path === '/health') {
    json_out(200, ['ok' => true]);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer '.$token) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Bad authorization header';
    exit;
}

$match = [];
if (! preg_match(
    '#^/api/actions_pipeline/_apis/pipelines/workflows/([^/]+)/artifacts(?:/(.*))?$#',
    $path,
    $match
)) {
    http_response_code(404);
    echo 'not found';
    exit;
}

$reqRun = $match[1];
$rest = $match[2] ?? '';
if ($reqRun !== $runId) {
    http_response_code(400);
    echo 'run id mismatch';
    exit;
}

$meta = load_meta($storeDir);
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$origin = $publicOrigin !== '' ? rtrim($publicOrigin, '/') : ('http://'.$host);

if ($method === 'POST' && $rest === '') {
    $req = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $name = (string) ($req['Name'] ?? $req['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo 'missing Name';
        exit;
    }
    $hash = md5($name);
    $meta['pending'][$name] = $meta['pending'][$name] ?? ['files' => [], 'confirmed' => false, 'ids' => []];
    save_meta($storeDir, $meta);
    json_out(200, [
        'fileContainerResourceUrl' => $origin.'/api/actions_pipeline/_apis/pipelines/workflows/'.$runId.'/artifacts/'.$hash.'/upload',
    ]);
}

if ($method === 'PUT' && str_ends_with($rest, '/upload')) {
    $itemPath = (string) ($query['itemPath'] ?? '');
    if (! str_contains($itemPath, '/')) {
        http_response_code(400);
        echo 'itemPath';
        exit;
    }
    [$name, $rel] = explode('/', $itemPath, 2);
    $hash = explode('/', $rest)[0];
    if (md5($name) !== $hash) {
        http_response_code(400);
        echo 'Invalid artifact hash';
        exit;
    }
    $body = (string) file_get_contents('php://input');
    $expected = $_SERVER['HTTP_X_ACTIONS_RESULTS_MD5'] ?? '';
    $got = base64_encode(md5($body, true));
    if ($expected !== $got) {
        http_response_code(400);
        echo 'md5 mismatch';
        exit;
    }
    $range = $_SERVER['HTTP_CONTENT_RANGE'] ?? '';
    if (! preg_match('/bytes (\d+)-(\d+)\/(\d+)$/', $range, $rm)) {
        http_response_code(400);
        echo 'Content-Range';
        exit;
    }
    $start = (int) $rm[1];
    $end = (int) $rm[2];
    $total = (int) $rm[3];
    $blobPath = $storeDir.'/pending-'.md5($name).'-'.md5($rel);
    $buf = is_file($blobPath) ? (string) file_get_contents($blobPath) : str_repeat("\0", $total);
    if (strlen($buf) !== $total) {
        $buf = str_repeat("\0", $total);
    }
    $buf = substr($buf, 0, $start).$body.substr($buf, $end + 1);
    file_put_contents($blobPath, $buf);
    $meta['pending'][$name]['files'][$rel] = $blobPath;
    if (! isset($meta['pending'][$name]['ids'][$rel])) {
        $meta['next_id'] = ($meta['next_id'] ?? 100) + 1;
        $id = (int) $meta['next_id'];
        $meta['pending'][$name]['ids'][$rel] = $id;
        $meta['blobs'][(string) $id] = [$name, $rel];
    }
    save_meta($storeDir, $meta);
    json_out(200, ['message' => 'success']);
}

if ($method === 'PATCH' && $rest === '') {
    $name = (string) ($query['artifactName'] ?? '');
    if ($name === '' || ! isset($meta['pending'][$name])) {
        http_response_code(400);
        echo 'artifact name is empty';
        exit;
    }
    $meta['pending'][$name]['confirmed'] = true;
    save_meta($storeDir, $meta);
    json_out(200, ['message' => 'success']);
}

if ($method === 'GET' && $rest === '') {
    $items = [];
    foreach ($meta['pending'] ?? [] as $name => $info) {
        if (empty($info['confirmed'])) {
            continue;
        }
        $items[] = [
            'name' => $name,
            'fileContainerResourceUrl' => $origin.'/api/actions_pipeline/_apis/pipelines/workflows/'.$runId.'/artifacts/'.md5($name).'/download_url',
        ];
    }
    if ($items === []) {
        http_response_code(404);
        echo 'not found';
        exit;
    }
    json_out(200, ['count' => count($items), 'value' => $items]);
}

if ($method === 'GET' && str_ends_with($rest, '/download_url')) {
    $name = (string) ($query['itemPath'] ?? '');
    $info = $meta['pending'][$name] ?? null;
    if (! is_array($info) || empty($info['confirmed'])) {
        http_response_code(404);
        echo 'not found';
        exit;
    }
    $values = [];
    foreach ($info['ids'] ?? [] as $rel => $id) {
        $values[] = [
            'path' => $name.'/'.$rel,
            'itemType' => 'file',
            'contentLocation' => $origin.'/api/actions_pipeline/_apis/pipelines/workflows/'.$runId.'/artifacts/'.$id.'/download',
        ];
    }
    json_out(200, ['value' => $values]);
}

if ($method === 'GET' && str_ends_with($rest, '/download')) {
    $id = explode('/', $rest)[0];
    $pair = $meta['blobs'][$id] ?? null;
    if (! is_array($pair)) {
        http_response_code(404);
        echo 'not found';
        exit;
    }
    [$name, $rel] = $pair;
    $blobPath = $meta['pending'][$name]['files'][$rel] ?? '';
    if (! is_file($blobPath)) {
        http_response_code(404);
        echo 'not found';
        exit;
    }
    $blob = (string) file_get_contents($blobPath);
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.strlen($blob));
    echo $blob;
    exit;
}

http_response_code(404);
echo 'not found';

/**
 * @return array<string, mixed>
 */
function load_meta(string $dir): array
{
    $path = $dir.'/meta.json';
    if (! is_file($path)) {
        return ['pending' => [], 'blobs' => [], 'next_id' => 100];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : ['pending' => [], 'blobs' => [], 'next_id' => 100];
}

/**
 * @param  array<string, mixed>  $meta
 */
function save_meta(string $dir, array $meta): void
{
    file_put_contents($dir.'/meta.json', json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX);
}

/**
 * @param  array<string, mixed>  $payload
 */
function json_out(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

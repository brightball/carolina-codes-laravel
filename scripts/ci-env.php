#!/usr/bin/env php
<?php

/**
 * Prepare/restore the Gitea CI workspace without Node or an OCI push.
 *
 * php:8.5-cli has no Node, so actions/upload-artifact cannot run inside it.
 * Gitea job tokens also cannot publish container packages. This helper talks
 * to Gitea's artifact API with PHP cURL instead.
 */
const CI_ENV_API_VERSION = '6.0-preview';
const CI_ENV_GITLEAKS_VERSION = '8.30.1';

function ci_env_main(array $argv): int
{
    $cmd = $argv[1] ?? '';
    match ($cmd) {
        'prepare' => cmd_prepare(),
        'restore' => cmd_restore(),
        'install' => cmd_install(),
        'pack' => cmd_pack(),
        'unpack' => cmd_unpack(),
        'upload' => cmd_upload(),
        'download' => cmd_download(),
        default => usage(),
    };

    return 0;
}

function usage(): never
{
    fwrite(STDERR, "usage: ci-env.php prepare|restore|install|pack|unpack|upload|download\n");
    exit(2);
}

function artifact_name(): string
{
    $name = getenv('CI_ENV_ARTIFACT_NAME');

    return ($name !== false && $name !== '') ? $name : 'prepared-env';
}

function tar_path(): string
{
    $path = getenv('CI_ENV_TAR');

    return ($path !== false && $path !== '') ? $path : '/tmp/prepared-env.tar.gz';
}

function workspace_dir(): string
{
    $ws = getenv('GITHUB_WORKSPACE');

    return ($ws !== false && $ws !== '') ? $ws : (string) getcwd();
}

function tools_dir(): string
{
    $dir = getenv('CI_ENV_TOOLS_DIR');

    return ($dir !== false && $dir !== '') ? $dir : '/usr/local/bin';
}

function php_ext_dir(): string
{
    $dir = getenv('CI_ENV_PHP_EXT_DIR');
    if ($dir !== false && $dir !== '') {
        return $dir;
    }

    $loaded = ini_get('extension_dir');

    return is_string($loaded) && $loaded !== '' ? $loaded : '/usr/local/lib/php/extensions';
}

function php_ini_dir(): string
{
    $dir = getenv('CI_ENV_PHP_INI_DIR');

    return ($dir !== false && $dir !== '') ? $dir : '/usr/local/etc/php/conf.d';
}

function php_lib_dir(): string
{
    $dir = getenv('CI_ENV_PHP_LIB_DIR');

    return ($dir !== false && $dir !== '') ? $dir : '/usr/local/lib';
}

function artifact_token(): string
{
    foreach (['ACTIONS_RUNTIME_TOKEN', 'GITHUB_TOKEN', 'GITEA_TOKEN'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }
    fwrite(STDERR, "missing artifact token (ACTIONS_RUNTIME_TOKEN/GITHUB_TOKEN)\n");
    exit(1);
}

function artifact_base(): string
{
    $runtime = getenv('ACTIONS_RUNTIME_URL');
    if (is_string($runtime) && $runtime !== '') {
        return rtrim($runtime, '/');
    }
    $server = getenv('GITHUB_SERVER_URL');
    if (! is_string($server) || $server === '') {
        fwrite(STDERR, "missing ACTIONS_RUNTIME_URL or GITHUB_SERVER_URL\n");
        exit(1);
    }

    return rtrim($server, '/').'/api/actions_pipeline';
}

function run_id(): string
{
    foreach (['GITHUB_RUN_ID', 'GITEA_RUN_ID'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }
    fwrite(STDERR, "missing GITHUB_RUN_ID\n");
    exit(1);
}

function artifacts_url(): string
{
    return artifact_base().'/_apis/pipelines/workflows/'.run_id().'/artifacts?api-version='.CI_ENV_API_VERSION;
}

function rewrite_runtime_url(string $url): string
{
    if ($url === '') {
        fwrite(STDERR, "artifact API returned an empty URL\n");
        exit(1);
    }
    $base = artifact_base();
    $parts = parse_url($url);
    if (! is_array($parts) || empty($parts['scheme'])) {
        return $base.'/'.ltrim($url, '/');
    }
    $baseParts = parse_url($base);
    if (! is_array($baseParts)) {
        return $url;
    }
    $parts['scheme'] = $baseParts['scheme'] ?? $parts['scheme'];
    $parts['host'] = $baseParts['host'] ?? ($parts['host'] ?? 'localhost');
    if (isset($baseParts['port'])) {
        $parts['port'] = $baseParts['port'];
    } else {
        unset($parts['port']);
    }

    return unparse_url($parts);
}

/**
 * @param  array<string, mixed>  $parts
 */
function unparse_url(array $parts): string
{
    $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':'.$parts['port'] : '';
    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

    return $scheme.$host.$port.$path.$query;
}

function export_env(string $key, string $value): void
{
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $githubEnv = getenv('GITHUB_ENV');
    if (is_string($githubEnv) && $githubEnv !== '') {
        file_put_contents($githubEnv, $key.'='.$value."\n", FILE_APPEND);
    }
}

function sh(string $cmd, ?string $cwd = null): void
{
    $old = getcwd();
    if ($cwd !== null) {
        if (! @chdir($cwd)) {
            fwrite(STDERR, "cannot chdir $cwd\n");
            exit(1);
        }
    }
    passthru($cmd, $code);
    if ($cwd !== null && is_string($old)) {
        chdir($old);
    }
    if ($code !== 0) {
        fwrite(STDERR, "command failed ($code): $cmd\n");
        exit($code ?: 1);
    }
}

function ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        fwrite(STDERR, "cannot mkdir $dir\n");
        exit(1);
    }
}

function copy_tree(string $src, string $dst): void
{
    ensure_dir($dst);
    sh('tar -C '.escapeshellarg($src).' -cf - . | tar -C '.escapeshellarg($dst).' -xf -');
}

function which_tool(string $name): ?string
{
    $forced = tools_dir().'/'.$name;
    if (is_file($forced)) {
        return $forced;
    }
    $out = [];
    exec('command -v '.escapeshellarg($name).' 2>/dev/null', $out, $code);
    if ($code === 0 && isset($out[0]) && $out[0] !== '') {
        return $out[0];
    }

    return null;
}

function cmd_install(): void
{
    echo 'installing Composer vendor/ and gitleaks '.CI_ENV_GITLEAKS_VERSION."\n";
    if (which_tool('composer') === null) {
        fwrite(STDERR, "composer is not on PATH; install it before prepare\n");
        exit(1);
    }
    sh('composer install --prefer-dist --no-interaction --no-progress', workspace_dir());
    cmd_install_gitleaks();
}

function cmd_install_gitleaks(): void
{
    $dir = tools_dir();
    ensure_dir($dir);
    $arch = php_uname('m');
    $asset = match ($arch) {
        'x86_64', 'amd64' => 'gitleaks_8.30.1_linux_x64.tar.gz',
        'aarch64', 'arm64' => 'gitleaks_8.30.1_linux_arm64.tar.gz',
        default => null,
    };
    if ($asset === null) {
        fwrite(STDERR, "unsupported arch: $arch\n");
        exit(1);
    }
    $url = 'https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/'.$asset;
    $tmp = sys_get_temp_dir().'/gitleaks-'.bin2hex(random_bytes(8)).'.tar.gz';
    http_download($url, $tmp, []);
    sh('tar -xz -C '.escapeshellarg($dir).' -f '.escapeshellarg($tmp).' gitleaks');
    chmod($dir.'/gitleaks', 0755);
    @unlink($tmp);
}

function cmd_pack(): void
{
    $ws = workspace_dir();
    $tar = tar_path();
    with_stage(function (string $stage) use ($ws, $tar): void {
        ensure_dir($stage.'/workspace');
        ensure_dir($stage.'/tools');
        ensure_dir($stage.'/php-ext');
        ensure_dir($stage.'/php-ini');
        ensure_dir($stage.'/php-lib');

        $exclude = '--exclude=prepared-env.tar.gz --exclude=.ci-env';
        sh('tar -C '.escapeshellarg($ws).' '.$exclude.' -cf - . | tar -C '.escapeshellarg($stage.'/workspace').' -xf -');

        pack_tools($stage.'/tools');
        pack_php_files($stage);

        ensure_dir(dirname($tar));
        sh('tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($tar).' workspace tools php-ext php-ini php-lib');
    });
    echo "packed $tar\n";
}

function pack_tools(string $dest): void
{
    $dir = getenv('CI_ENV_TOOLS_DIR');
    if (is_string($dir) && $dir !== '' && is_dir($dir)) {
        copy_tree($dir, $dest);

        return;
    }
    foreach (['composer', 'gitleaks'] as $name) {
        $src = which_tool($name);
        if ($src === null) {
            fwrite(STDERR, "missing tool $name; install it before packing\n");
            exit(1);
        }
        $target = $dest.'/'.$name;
        if (! copy($src, $target)) {
            fwrite(STDERR, "cannot copy $src to $target\n");
            exit(1);
        }
        chmod($target, 0755);
    }
}

function pack_php_files(string $stage): void
{
    $extDir = php_ext_dir();
    if (is_dir($extDir)) {
        copy_tree($extDir, $stage.'/php-ext');
        pack_shared_libs($extDir, $stage.'/php-lib');
    }
    $iniDir = php_ini_dir();
    if (is_dir($iniDir)) {
        copy_tree($iniDir, $stage.'/php-ini');
    }
}

function pack_shared_libs(string $extDir, string $dest): void
{
    $skip = '/^(libc|libm|libdl|libpthread|librt|libgcc_s|ld-linux)/';
    foreach (glob($extDir.'/*.so') ?: [] as $so) {
        $out = [];
        exec('ldd '.escapeshellarg($so).' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            continue;
        }
        foreach ($out as $line) {
            if (! preg_match('/=>\s+(\/\S+)/', $line, $match)) {
                continue;
            }
            $lib = $match[1];
            $base = basename($lib);
            if (preg_match($skip, $base) === 1) {
                continue;
            }
            if (! is_file($lib)) {
                continue;
            }
            $target = $dest.'/'.$base;
            if (! is_file($target)) {
                copy($lib, $target);
            }
        }
    }
}

function cmd_unpack(): void
{
    $tar = tar_path();
    if (! is_file($tar)) {
        fwrite(STDERR, "missing tarball $tar\n");
        exit(1);
    }
    $ws = workspace_dir();
    $tools = tools_dir();
    $extDir = php_ext_dir();
    $iniDir = php_ini_dir();
    $libDir = php_lib_dir();
    with_stage(function (string $stage) use ($tar, $ws, $tools, $extDir, $iniDir, $libDir): void {
        sh('tar -C '.escapeshellarg($stage).' -xzf '.escapeshellarg($tar));
        ensure_dir($ws);
        if (is_dir($stage.'/workspace')) {
            copy_tree($stage.'/workspace', $ws);
        }
        if (is_dir($stage.'/tools')) {
            ensure_dir($tools);
            copy_tree($stage.'/tools', $tools);
            foreach (glob($tools.'/*') ?: [] as $bin) {
                if (is_file($bin)) {
                    chmod($bin, 0755);
                }
            }
        }
        if (is_dir($stage.'/php-ext')) {
            ensure_dir($extDir);
            copy_tree($stage.'/php-ext', $extDir);
        }
        if (is_dir($stage.'/php-ini')) {
            ensure_dir($iniDir);
            copy_tree($stage.'/php-ini', $iniDir);
        }
        if (is_dir($stage.'/php-lib')) {
            ensure_dir($libDir);
            copy_tree($stage.'/php-lib', $libDir);
        }
    });
    $path = $tools.':'.(getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
    export_env('PATH', $path);
    $ld = getenv('LD_LIBRARY_PATH') ?: '';
    export_env('LD_LIBRARY_PATH', $ld === '' ? $libDir : $libDir.':'.$ld);
    if (is_executable('/sbin/ldconfig') || is_executable('/usr/sbin/ldconfig')) {
        @exec('ldconfig 2>/dev/null');
    }
    echo "restored workspace=$ws tools=$tools php-ext=$extDir\n";
}

/**
 * @param  callable(string):void  $fn
 */
function with_stage(callable $fn): void
{
    $stage = sys_get_temp_dir().'/ci-env-'.bin2hex(random_bytes(8));
    ensure_dir($stage);
    try {
        $fn($stage);
    } finally {
        sh('rm -rf '.escapeshellarg($stage));
    }
}

function cmd_upload(): void
{
    $tar = tar_path();
    if (! is_file($tar)) {
        fwrite(STDERR, "missing tarball $tar\n");
        exit(1);
    }
    $size = filesize($tar);
    if ($size === false || $size < 1) {
        fwrite(STDERR, "refusing to upload empty artifact: $tar\n");
        exit(1);
    }
    $token = artifact_token();
    $name = artifact_name();
    $created = http_json('POST', artifacts_url(), $token, json_encode([
        'Type' => 'actions_storage',
        'Name' => $name,
        'RetentionDays' => 1,
    ], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    $uploadUrl = first_string($created, 'fileContainerResourceUrl');
    if ($uploadUrl === '') {
        $uploadUrl = first_string($created, 'fileContainerResourceURL');
    }
    if ($uploadUrl === '') {
        fwrite(STDERR, "artifact create did not return fileContainerResourceUrl\n");
        exit(1);
    }
    $uploadUrl = rewrite_runtime_url($uploadUrl);
    $filename = basename($tar);
    $item = rawurlencode($name.'/'.$filename);
    $sep = str_contains($uploadUrl, '?') ? '&' : '?';
    $putUrl = $uploadUrl.$sep.'itemPath='.$item;
    $chunkSize = (int) (getenv('CI_ARTIFACT_CHUNK_SIZE') ?: 8 * 1024 * 1024);
    if ($chunkSize < 1) {
        $chunkSize = 8 * 1024 * 1024;
    }
    $fh = fopen($tar, 'rb');
    if ($fh === false) {
        fwrite(STDERR, "cannot read $tar\n");
        exit(1);
    }
    $sent = 0;
    while ($sent < $size) {
        $chunk = fread($fh, $chunkSize);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $end = $sent + strlen($chunk) - 1;
        $md5 = base64_encode(md5($chunk, true));
        http_request('PUT', $putUrl, $token, $chunk, [
            'Content-Type' => 'application/octet-stream',
            'Content-Range' => "bytes {$sent}-{$end}/{$size}",
            'x-tfs-filelength' => (string) $size,
            'x-actions-results-md5' => $md5,
        ]);
        $sent += strlen($chunk);
    }
    fclose($fh);
    $confirm = artifacts_url().'&artifactName='.rawurlencode($name);
    http_request('PATCH', $confirm, $token, '');
    echo "uploaded artifact {$name}\n";
}

function cmd_download(): void
{
    $token = artifact_token();
    $name = artifact_name();
    $listing = http_json('GET', artifacts_url(), $token);
    $container = named_or_first_string($listing, $name, 'fileContainerResourceUrl');
    if ($container === '') {
        $container = named_or_first_string($listing, $name, 'fileContainerResourceURL');
    }
    if ($container === '') {
        fwrite(STDERR, "artifact list did not return fileContainerResourceUrl\n");
        exit(1);
    }
    $container = rewrite_runtime_url($container);
    $sep = str_contains($container, '?') ? '&' : '?';
    $files = http_json('GET', $container.$sep.'itemPath='.rawurlencode($name), $token);
    $contentUrl = first_string($files, 'contentLocation');
    $itemPath = first_string($files, 'path');
    if ($contentUrl === '') {
        fwrite(STDERR, "artifact download_url did not return contentLocation\n");
        exit(1);
    }
    $contentUrl = rewrite_runtime_url($contentUrl);
    if ($itemPath !== '') {
        $encoded = str_replace('/', '%2F', $itemPath);
        $join = str_contains($contentUrl, '?') ? '&' : '?';
        if (! str_contains($contentUrl, 'itemPath=')) {
            $contentUrl .= $join.'itemPath='.$encoded;
        }
    }
    $tar = tar_path();
    ensure_dir(dirname($tar));
    http_download($contentUrl, $tar, ['Authorization: Bearer '.$token]);
    echo "downloaded $tar\n";
}

function cmd_prepare(): void
{
    if (getenv('CI_ENV_SKIP_INSTALL') !== '1') {
        cmd_install();
    }
    cmd_pack();
    cmd_upload();
}

function cmd_restore(): void
{
    echo "restoring prepared environment\n";
    cmd_download();
    cmd_unpack();
}

/**
 * @param  array<string, mixed>  $json
 */
function first_string(array $json, string $key): string
{
    if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
        return $json[$key];
    }
    $values = $json['value'] ?? null;
    if (! is_array($values)) {
        return '';
    }
    foreach ($values as $item) {
        if (is_array($item) && isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
            return $item[$key];
        }
    }

    return '';
}

/**
 * @param  array<string, mixed>  $json
 */
function named_or_first_string(array $json, string $name, string $key): string
{
    $values = $json['value'] ?? null;
    if (is_array($values)) {
        foreach ($values as $item) {
            if (is_array($item) && ($item['name'] ?? '') === $name && isset($item[$key]) && is_string($item[$key])) {
                return $item[$key];
            }
        }
    }

    return first_string($json, $key);
}

/**
 * @param  array<string, string>  $headers
 * @return array<string, mixed>
 */
function http_json(string $method, string $url, string $token, ?string $body = null, array $headers = []): array
{
    $raw = http_request($method, $url, $token, $body, $headers);
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "$method $url: invalid JSON: {$e->getMessage()}\n");
        exit(1);
    }
    if (! is_array($decoded)) {
        fwrite(STDERR, "$method $url: JSON object expected\n");
        exit(1);
    }

    return $decoded;
}

/**
 * @param  array<string, string>  $headers
 */
function http_request(string $method, string $url, string $token, ?string $body = null, array $headers = []): string
{
    $headerList = ['Authorization: Bearer '.$token];
    foreach ($headers as $name => $value) {
        $headerList[] = $name.': '.$value;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, "curl_init failed\n");
        exit(1);
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headerList,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $result = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($result === false || $code >= 400 || $code === 0) {
        $snippet = is_string($result) ? $result : $err;
        fwrite(STDERR, "$method $url failed: $code $snippet\n");
        exit(1);
    }

    return is_string($result) ? $result : '';
}

/**
 * @param  list<string>  $headers
 */
function http_download(string $url, string $dest, array $headers): void
{
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, "curl_init failed\n");
        exit(1);
    }
    $fp = fopen($dest, 'wb');
    if ($fp === false) {
        fwrite(STDERR, "cannot write $dest\n");
        exit(1);
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $code >= 400 || $code === 0) {
        fwrite(STDERR, "GET $url failed: $code $err\n");
        exit(1);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    exit(ci_env_main($argv));
}

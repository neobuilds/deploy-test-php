<?php

declare(strict_types=1);
require __DIR__.'/../src/config.php';
require __DIR__.'/../src/db.php';
require __DIR__.'/../src/api.php';
$url = getenv('TEST_DATABASE_URL') ?: throw new RuntimeException('TEST_DATABASE_URL is required');
$fileEnv = php_tb_parse_environment("# Copy to .env (or export in the platform environment).\nDATABASE_URL=\"".$url."\"\n");
$cfg = PhpTaskboard_Cfg::fromEnv($fileEnv);
if ($cfg->dbPath !== $url) { throw new RuntimeException('Dotenv comments prevented PostgreSQL configuration'); }
function callPg(string $method, string $path, ?array $body = null, array $query = [], int $status = 200): array {
    global $url, $cfg;
    $response = php_tb_api_dispatch($method, explode('/', trim($path, '/')), $query, $body, false, 'csrf', 'csrf', $url, $cfg);
    if ($response['status'] !== $status) { throw new RuntimeException("$method $path returned ".$response['status']); }
    return $response['body'] ?? [];
}
function verifyPg(bool $condition): void { if (!$condition) { throw new RuntimeException('PostgreSQL assertion failed'); } }
verifyPg(callPg('GET', '/api/health/ready')['db'] === 'postgresql');
$p = callPg('POST', '/api/projects', ['name' => 'PG acceptance'], [], 201)['project']['id'];
$t = callPg('POST', '/api/tasks', ['project_id' => $p, 'title' => 'PG task'], [], 201)['task']['id'];
callPg('PATCH', "/api/tasks/$t", ['title' => 'PG updated', 'description' => 'persist', 'status' => 'done', 'priority' => 'high', 'project_id' => $p]);
verifyPg(callPg('GET', "/api/tasks/$t")['task']['title'] === 'PG updated');
verifyPg(callPg('GET', '/api/tasks', null, ['q' => 'PG updated', 'status' => 'done'])['count'] === 1);
callPg('POST', '/api/tasks', ['title' => ''], [], 400);
callPg('GET', '/api/tasks/99999999', null, [], 404);
callPg('DELETE', "/api/tasks/$t", null, [], 204);
callPg('DELETE', "/api/projects/$p", null, [], 204);
verifyPg(!str_contains(json_encode(callPg('GET', '/api/meta')), $url));
verifyPg(!php_tb_probe('postgresql://postgres@127.0.0.1:1/missing')['ok']);
echo "PASS PostgreSQL schema, seed, real CRUD, search, validation, readiness, missing dependency, redacted metadata\n";

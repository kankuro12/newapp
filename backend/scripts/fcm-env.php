<?php

// Copies project_id, client_email and private_key from a Firebase service-account JSON into backend/.env.
// Usage (from the project root): php84 backend/scripts/fcm-env.php "path\to\service-account.json"
// Prints nothing secret. Delete this script if you prefer not to keep it.

$json = $argv[1] ?? null;
if (! $json || ! is_file($json)) {
    fwrite(STDERR, "Pass the service-account JSON path.\n");
    exit(1);
}
$account = json_decode(file_get_contents($json), true);
foreach (['project_id', 'client_email', 'private_key'] as $field) {
    if (empty($account[$field]) || ! is_string($account[$field])) {
        fwrite(STDERR, "Missing $field in JSON.\n");
        exit(1);
    }
}
$envFile = __DIR__.'/../.env';
$env = preg_replace('/^FCM_(PROJECT_ID|CLIENT_EMAIL|PRIVATE_KEY)=.*\R?/m', '', file_get_contents($envFile));
$env = rtrim($env)."\n"
    .'FCM_PROJECT_ID='.$account['project_id']."\n"
    .'FCM_CLIENT_EMAIL='.$account['client_email']."\n"
    .'FCM_PRIVATE_KEY="'.str_replace("\n", '\n', $account['private_key'])."\"\n";
file_put_contents($envFile, $env);
echo "FCM settings written to backend/.env for project {$account['project_id']}.\n";

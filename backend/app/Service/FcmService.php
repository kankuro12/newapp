<?php

namespace App\Service;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class FcmService
{
    public function ready(): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{4,62}$/D', (string) config('services.fcm.project_id')) === 1
            && filter_var(config('services.fcm.client_email'), FILTER_VALIDATE_EMAIL)
            && (bool) config('services.fcm.private_key');
    }

    public function send(string $token, array $notification, string $targetKind = 'token'): array
    {
        if (! in_array($targetKind, ['token', 'fid'], true)) {
            throw new \InvalidArgumentException('FCM target unavailable.');
        }
        $payload = [$targetKind => $token, 'notification' => ['title' => (string) $notification['title'], 'body' => (string) $notification['body']]];
        if (isset($notification['url'])) {
            $url = parse_url($notification['url']);
            $base = parse_url(config('app.frontend_url'));
            if (! is_array($url) || ! is_array($base) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || ($url['host'] ?? '') !== ($base['host'] ?? '') || ($url['port'] ?? 443) !== ($base['port'] ?? 443)) {
                throw new \InvalidArgumentException('FCM link unavailable.');
            }
            $payload['webpush']['fcm_options']['link'] = $notification['url'];
        }
        if (! $this->ready()) {
            return ['status' => 'unavailable', 'error_code' => 'fcm_not_configured'];
        }
        try {
            $cacheKey = 'fcm-oauth:'.hash('sha256', config('services.fcm.project_id').'|'.config('services.fcm.client_email').'|'.config('services.fcm.private_key'));
            $cached = Cache::get($cacheKey);
            $access = is_string($cached) ? Crypt::decryptString($cached) : null;
            if (! $access) {
                $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
                $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
                $claims = $encode(json_encode(['iss' => config('services.fcm.client_email'), 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600], JSON_THROW_ON_ERROR));
                if (! openssl_sign($header.'.'.$claims, $signature, str_replace('\\n', "\n", config('services.fcm.private_key')), OPENSSL_ALGO_SHA256)) {
                    return ['status' => 'unavailable', 'error_code' => 'fcm_credentials_invalid'];
                }
                $auth = Http::asForm()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $header.'.'.$claims.'.'.$encode($signature)]);
                $access = $auth->json('access_token');
                if (! $auth->successful() || ! is_string($access) || $access === '') {
                    return ['status' => 'unavailable', 'error_code' => 'fcm_auth_failed'];
                }
                $lifetime = $auth->json('expires_in');
                if (is_int($lifetime) && $lifetime > 120) {
                    Cache::put($cacheKey, Crypt::encryptString($access), min(3600, $lifetime) - 120);
                }
            }
            $response = Http::withToken($access)->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', ['message' => $payload]);
            if ($response->status() === 401) {
                Cache::forget($cacheKey);
            }
            $name = $response->json('name');
            if ($response->successful() && is_string($name) && preg_match('~^projects/[^/]+/messages/[^/]+$~D', $name)) {
                return ['status' => 'sent', 'provider_id' => $name];
            }
            foreach ((array) $response->json('error.details', []) as $detail) {
                if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError' && ($detail['errorCode'] ?? '') === 'UNREGISTERED') {
                    return ['status' => 'invalid', 'error_code' => 'token_unregistered'];
                }
            }

            return ['status' => $response->status() >= 500 ? 'unknown' : 'failed', 'error_code' => 'fcm_send_rejected'];
        } catch (\Throwable) {
            // Request may already be accepted; caller must not blindly resend.
            return ['status' => 'unknown', 'error_code' => 'fcm_outcome_unknown'];
        }
    }
}

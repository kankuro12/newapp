<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;

/**
 * Meta WhatsApp Cloud API template sender. Business-initiated messages must use approved templates.
 */
class WhatsappService
{
    public function template(string $event): ?string
    {
        $name = config('services.whatsapp.templates.'.$event);

        return is_string($name) && preg_match('/^[a-z0-9_]{1,512}$/D', $name) ? $name : null;
    }

    public function ready(string $event): bool
    {
        return is_string(config('services.whatsapp.token')) && config('services.whatsapp.token') !== ''
            && preg_match('/^[0-9]{5,30}$/D', (string) config('services.whatsapp.phone_number_id')) === 1
            && $this->template($event) !== null;
    }

    /**
     * Stored local number plus the country calling code, as digits only (E.164 without "+").
     */
    public function address(?string $country, ?string $phone): ?string
    {
        if (! is_string($country) || ! is_string($phone) || ! preg_match('/^[0-9]{4,15}$/D', $phone)) {
            return null;
        }
        static $codes = null;
        $codes ??= array_column(json_decode(file_get_contents(resource_path('country-calling-codes.json')), true, 512, JSON_THROW_ON_ERROR)['countries'], 'calling_code', 'code');
        $calling = $codes[strtoupper($country)] ?? null;
        if (! is_string($calling) && ! is_int($calling)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $calling).ltrim($phone, '0');

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * @param  list<string>  $parameters  body placeholders in template order
     * @return array{status: string, provider_id?: string, error_code?: string}
     */
    public function send(string $to, string $event, array $parameters): array
    {
        if (! $this->ready($event)) {
            return ['status' => 'skipped', 'error_code' => 'whatsapp_not_configured'];
        }
        $version = preg_match('/^v[0-9]{1,3}\.[0-9]$/D', (string) config('services.whatsapp.api_version')) ? config('services.whatsapp.api_version') : 'v21.0';
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'template', 'template' => [
            'name' => $this->template($event), 'language' => ['code' => (string) (config('services.whatsapp.language') ?: 'en')],
            'components' => [['type' => 'body', 'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => mb_substr((string) $text, 0, 1000)], $parameters)]],
        ]];
        try {
            $response = Http::withToken(config('services.whatsapp.token'))->acceptJson()->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])
                ->post('https://graph.facebook.com/'.$version.'/'.config('services.whatsapp.phone_number_id').'/messages', $payload);
        } catch (\Throwable) {
            // The request may have been accepted; never blindly resend.
            return ['status' => 'unknown', 'error_code' => 'whatsapp_outcome_unknown'];
        }
        $id = $response->json('messages.0.id');
        if ($response->successful() && is_string($id) && $id !== '') {
            return ['status' => 'sent', 'provider_id' => mb_substr($id, 0, 255)];
        }

        return ['status' => $response->serverError() ? 'unknown' : 'failed', 'error_code' => 'whatsapp_send_rejected'];
    }
}
